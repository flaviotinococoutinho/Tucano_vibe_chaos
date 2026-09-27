"""Creates the AWS resources the services expect: S3 buckets, SQS queues with
dead-letter queues, the SNS fan-out topic and its subscriptions, DynamoDB tables
and the SES sender identity.

Floci runs this hook on every start, so every step is idempotent.
"""

import json
from pathlib import Path

import boto3
from botocore.exceptions import ClientError

s3 = boto3.client("s3")
sqs = boto3.client("sqs")
sns = boto3.client("sns")
dynamodb = boto3.client("dynamodb")
ses = boto3.client("ses")

BUCKETS = ["tucano-labels", "tucano-media"]
SENDER = "no-reply@tucano.example"
TABLE_SPECS = Path("/etc/floci/dynamodb")
TWO_WEEKS = str(14 * 24 * 60 * 60)

# Only delivery milestones become push notifications; e-mail gets everything.
PUSH_FILTER = {"kind": ["shipment.out_for_delivery", "shipment.delivered", "shipment.delivery_failed"]}


def ensure_bucket(name):
    existing = {bucket["Name"] for bucket in s3.list_buckets().get("Buckets", [])}
    if name not in existing:
        s3.create_bucket(Bucket=name)


def queue_arn(queue_url):
    attributes = sqs.get_queue_attributes(QueueUrl=queue_url, AttributeNames=["QueueArn"])
    return attributes["Attributes"]["QueueArn"]


def ensure_queue(name, attributes=None):
    queue_url = sqs.create_queue(QueueName=name)["QueueUrl"]
    if attributes:
        sqs.set_queue_attributes(QueueUrl=queue_url, Attributes=attributes)
    return queue_url


def ensure_queue_with_dlq(name, max_receives):
    dlq_url = ensure_queue(f"{name}-dlq", {"MessageRetentionPeriod": TWO_WEEKS})
    redrive = {"deadLetterTargetArn": queue_arn(dlq_url), "maxReceiveCount": str(max_receives)}
    return ensure_queue(name, {"VisibilityTimeout": "60", "RedrivePolicy": json.dumps(redrive)})


def allow_topic_to_send(queue_url, topic_arn):
    policy = {
        "Version": "2012-10-17",
        "Statement": [{
            "Effect": "Allow",
            "Principal": {"Service": "sns.amazonaws.com"},
            "Action": "sqs:SendMessage",
            "Resource": queue_arn(queue_url),
            "Condition": {"ArnEquals": {"aws:SourceArn": topic_arn}},
        }],
    }
    sqs.set_queue_attributes(QueueUrl=queue_url, Attributes={"Policy": json.dumps(policy)})


def ensure_subscription(topic_arn, queue_url, filter_policy=None):
    endpoint = queue_arn(queue_url)
    subscriptions = sns.list_subscriptions_by_topic(TopicArn=topic_arn)["Subscriptions"]
    if any(subscription["Endpoint"] == endpoint for subscription in subscriptions):
        return

    attributes = {"RawMessageDelivery": "true"}
    if filter_policy:
        attributes["FilterPolicy"] = json.dumps(filter_policy)
    sns.subscribe(TopicArn=topic_arn, Protocol="sqs", Endpoint=endpoint, Attributes=attributes)


def ensure_table(spec_file):
    spec = json.loads(spec_file.read_text())
    table = spec["table"]
    name = table["TableName"]

    if name not in dynamodb.list_tables()["TableNames"]:
        dynamodb.create_table(**table)
        dynamodb.get_waiter("table_exists").wait(TableName=name)

    ttl = spec.get("timeToLive")
    if not ttl:
        return
    try:
        dynamodb.update_time_to_live(
            TableName=name,
            TimeToLiveSpecification={"Enabled": True, "AttributeName": ttl["AttributeName"]},
        )
    except ClientError as error:
        if "already enabled" not in str(error).lower():
            raise


def main():
    for bucket in BUCKETS:
        ensure_bucket(bucket)

    email_queue = ensure_queue_with_dlq("email-notifications", max_receives=5)
    push_queue = ensure_queue("push-notifications")
    ensure_queue_with_dlq("label-jobs", max_receives=3)

    topic_arn = sns.create_topic(Name="customer-notifications")["TopicArn"]
    for queue_url in (email_queue, push_queue):
        allow_topic_to_send(queue_url, topic_arn)
    ensure_subscription(topic_arn, email_queue)
    ensure_subscription(topic_arn, push_queue, PUSH_FILTER)

    for spec_file in sorted(TABLE_SPECS.glob("*.json")):
        ensure_table(spec_file)

    ses.verify_email_identity(EmailAddress=SENDER)
    print("aws resources ready")


main()
