<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0020: the thoroughfare as a type and a name, the number as text, and the
 * territory as a list of divisions from the state down.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE shipments
                ADD COLUMN dest_thoroughfare_type varchar(30),
                ADD COLUMN dest_thoroughfare_name varchar(160),
                ADD COLUMN dest_divisions         jsonb;

            -- The old street held the type inside the name. Its first word is the type when it is
            -- a known one (abbreviations too); anything else becomes Rua with the whole text as the name.
            -- The old district was the neighborhood: IBGE districts are a level above it.
            UPDATE shipments AS t
               SET dest_thoroughfare_type = coalesce(known.type, 'Rua'),
                   dest_thoroughfare_name = CASE WHEN known.type IS NULL OR legacy.rest = '' THEN btrim(t.dest_street) ELSE legacy.rest END,
                   dest_divisions = jsonb_build_array(
                       jsonb_build_object('kind', 'state', 'code', t.dest_state, 'name', states.name),
                       jsonb_build_object('kind', 'municipality', 'code', NULL, 'name', t.dest_city),
                       jsonb_build_object('kind', 'neighborhood', 'code', NULL, 'name', t.dest_district))
              FROM (SELECT id,
                           lower(rtrim(split_part(btrim(dest_street), ' ', 1), '.')) AS first_word,
                           btrim(substr(btrim(dest_street), length(split_part(btrim(dest_street), ' ', 1)) + 1)) AS rest
                      FROM shipments) AS legacy
              LEFT JOIN (VALUES ('rua', 'Rua'), ('r', 'Rua'), ('avenida', 'Avenida'), ('av', 'Avenida'), ('rodovia', 'Rodovia'), ('rod', 'Rodovia'),
                                ('estrada', 'Estrada'), ('est', 'Estrada'), ('travessa', 'Travessa'), ('tv', 'Travessa'), ('alameda', 'Alameda'), ('al', 'Alameda'),
                                ('praça', 'Praça'), ('praca', 'Praça'), ('pça', 'Praça'), ('largo', 'Largo'), ('ladeira', 'Ladeira'), ('viela', 'Viela'),
                                ('beco', 'Beco')) AS known (word, type)
                     ON known.word = legacy.first_word,
                   (VALUES ('AC', 'Acre'), ('AL', 'Alagoas'), ('AP', 'Amapá'), ('AM', 'Amazonas'), ('BA', 'Bahia'),
                            ('CE', 'Ceará'), ('DF', 'Distrito Federal'), ('ES', 'Espírito Santo'), ('GO', 'Goiás'), ('MA', 'Maranhão'),
                            ('MT', 'Mato Grosso'), ('MS', 'Mato Grosso do Sul'), ('MG', 'Minas Gerais'), ('PA', 'Pará'), ('PB', 'Paraíba'),
                            ('PR', 'Paraná'), ('PE', 'Pernambuco'), ('PI', 'Piauí'), ('RJ', 'Rio de Janeiro'), ('RN', 'Rio Grande do Norte'),
                            ('RS', 'Rio Grande do Sul'), ('RO', 'Rondônia'), ('RR', 'Roraima'), ('SC', 'Santa Catarina'), ('SP', 'São Paulo'),
                            ('SE', 'Sergipe'), ('TO', 'Tocantins')) AS states (uf, name)
             WHERE t.id = legacy.id AND states.uf = t.dest_state;

            ALTER TABLE shipments
                ALTER COLUMN dest_thoroughfare_type SET NOT NULL,
                ALTER COLUMN dest_thoroughfare_name SET NOT NULL,
                ALTER COLUMN dest_divisions SET NOT NULL,
                ALTER COLUMN dest_number TYPE varchar(20),
                DROP COLUMN dest_street,
                DROP COLUMN dest_district,
                DROP COLUMN dest_city,
                DROP COLUMN dest_state,
                ADD CONSTRAINT shipments_dest_thoroughfare_check
                    CHECK (btrim(dest_thoroughfare_type) <> '' AND btrim(dest_thoroughfare_name) <> ''),
                ADD CONSTRAINT shipments_dest_number_check CHECK (btrim(dest_number) <> ''),
                -- A list that starts with the state, known by its UF, and the municipality; the
                -- domain checks the rest. CASE keeps jsonb_array_length away from a non-array, and
                -- coalesce turns a missing key into a violation instead of a pass.
                ADD CONSTRAINT shipments_dest_divisions_check CHECK (coalesce(
                    CASE WHEN jsonb_typeof(dest_divisions) = 'array' THEN
                        jsonb_array_length(dest_divisions) BETWEEN 2 AND 5
                        AND dest_divisions -> 0 ->> 'kind' = 'state'
                        AND dest_divisions -> 0 ->> 'code' ~ '^[A-Z]{2}$'
                        AND dest_divisions -> 1 ->> 'kind' = 'municipality'
                    END, false));

            COMMENT ON COLUMN shipments.dest_number IS 'Text, not an integer: KM 500 on a highway, S/N, 120-A.';
            COMMENT ON COLUMN shipments.dest_divisions IS 'Territorial divisions from the state down, as [{kind, code, name}]; see ADR 0020.';

            -- The official division is the municipality; the city is its seat.
            ALTER TABLE fulfillment_centers RENAME COLUMN city TO municipality;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE shipments
                ADD COLUMN dest_street   varchar(160),
                ADD COLUMN dest_district varchar(80),
                ADD COLUMN dest_city     varchar(80),
                ADD COLUMN dest_state    char(2);

            UPDATE shipments
               SET dest_street = left(dest_thoroughfare_type || ' ' || dest_thoroughfare_name, 160),
                   dest_district = left(coalesce(
                       (SELECT division ->> 'name' FROM jsonb_array_elements(dest_divisions) AS division WHERE division ->> 'kind' = 'neighborhood'),
                       dest_divisions -> 1 ->> 'name'), 80),
                   dest_city = left(dest_divisions -> 1 ->> 'name', 80),
                   dest_state = dest_divisions -> 0 ->> 'code';

            ALTER TABLE shipments
                DROP CONSTRAINT shipments_dest_number_check,
                DROP COLUMN dest_thoroughfare_type,
                DROP COLUMN dest_thoroughfare_name,
                DROP COLUMN dest_divisions,
                ALTER COLUMN dest_number TYPE varchar(16) USING left(dest_number, 16),
                ALTER COLUMN dest_street SET NOT NULL,
                ALTER COLUMN dest_district SET NOT NULL,
                ALTER COLUMN dest_city SET NOT NULL,
                ALTER COLUMN dest_state SET NOT NULL,
                ADD CONSTRAINT shipments_dest_state_check CHECK (dest_state ~ '^[A-Z]{2}$');

            ALTER TABLE fulfillment_centers RENAME COLUMN municipality TO city;
        SQL);
    }
};
