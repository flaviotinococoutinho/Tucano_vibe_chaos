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
            ALTER TABLE orders
                ADD COLUMN ship_thoroughfare_type varchar(30),
                ADD COLUMN ship_thoroughfare_name varchar(160),
                ADD COLUMN ship_divisions         jsonb;

            -- The old street held the type inside the name. Its first word is the type when it is
            -- a known one (abbreviations too); anything else becomes Rua with the whole text as the name.
            -- The old district was the neighborhood: IBGE districts are a level above it.
            UPDATE orders AS t
               SET ship_thoroughfare_type = coalesce(known.type, 'Rua'),
                   ship_thoroughfare_name = CASE WHEN known.type IS NULL OR legacy.rest = '' THEN btrim(t.ship_street) ELSE legacy.rest END,
                   ship_divisions = jsonb_build_array(
                       jsonb_build_object('kind', 'state', 'code', t.ship_state, 'name', states.name),
                       jsonb_build_object('kind', 'municipality', 'code', NULL, 'name', t.ship_city),
                       jsonb_build_object('kind', 'neighborhood', 'code', NULL, 'name', t.ship_district))
              FROM (SELECT id,
                           lower(rtrim(split_part(btrim(ship_street), ' ', 1), '.')) AS first_word,
                           btrim(substr(btrim(ship_street), length(split_part(btrim(ship_street), ' ', 1)) + 1)) AS rest
                      FROM orders) AS legacy
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
             WHERE t.id = legacy.id AND states.uf = t.ship_state;

            ALTER TABLE orders
                ALTER COLUMN ship_thoroughfare_type SET NOT NULL,
                ALTER COLUMN ship_thoroughfare_name SET NOT NULL,
                ALTER COLUMN ship_divisions SET NOT NULL,
                ALTER COLUMN ship_number TYPE varchar(20),
                DROP COLUMN ship_street,
                DROP COLUMN ship_district,
                DROP COLUMN ship_city,
                DROP COLUMN ship_state,
                ADD CONSTRAINT orders_ship_thoroughfare_check
                    CHECK (btrim(ship_thoroughfare_type) <> '' AND btrim(ship_thoroughfare_name) <> ''),
                ADD CONSTRAINT orders_ship_number_check CHECK (btrim(ship_number) <> ''),
                -- A list that starts with the state, known by its UF, and the municipality; the
                -- domain checks the rest. CASE keeps jsonb_array_length away from a non-array, and
                -- coalesce turns a missing key into a violation instead of a pass.
                ADD CONSTRAINT orders_ship_divisions_check CHECK (coalesce(
                    CASE WHEN jsonb_typeof(ship_divisions) = 'array' THEN
                        jsonb_array_length(ship_divisions) BETWEEN 2 AND 5
                        AND ship_divisions -> 0 ->> 'kind' = 'state'
                        AND ship_divisions -> 0 ->> 'code' ~ '^[A-Z]{2}$'
                        AND ship_divisions -> 1 ->> 'kind' = 'municipality'
                    END, false));

            COMMENT ON COLUMN orders.ship_number IS 'Text, not an integer: KM 500 on a highway, S/N, 120-A.';
            COMMENT ON COLUMN orders.ship_divisions IS 'Territorial divisions from the state down, as [{kind, code, name}]; see ADR 0020.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders
                ADD COLUMN ship_street   varchar(160),
                ADD COLUMN ship_district varchar(80),
                ADD COLUMN ship_city     varchar(80),
                ADD COLUMN ship_state    char(2);

            UPDATE orders
               SET ship_street = left(ship_thoroughfare_type || ' ' || ship_thoroughfare_name, 160),
                   ship_district = left(coalesce(
                       (SELECT division ->> 'name' FROM jsonb_array_elements(ship_divisions) AS division WHERE division ->> 'kind' = 'neighborhood'),
                       ship_divisions -> 1 ->> 'name'), 80),
                   ship_city = left(ship_divisions -> 1 ->> 'name', 80),
                   ship_state = ship_divisions -> 0 ->> 'code';

            ALTER TABLE orders
                DROP CONSTRAINT orders_ship_number_check,
                DROP COLUMN ship_thoroughfare_type,
                DROP COLUMN ship_thoroughfare_name,
                DROP COLUMN ship_divisions,
                ALTER COLUMN ship_number TYPE varchar(16) USING left(ship_number, 16),
                ALTER COLUMN ship_street SET NOT NULL,
                ALTER COLUMN ship_district SET NOT NULL,
                ALTER COLUMN ship_city SET NOT NULL,
                ALTER COLUMN ship_state SET NOT NULL,
                ADD CONSTRAINT orders_ship_state_check CHECK (ship_state ~ '^[A-Z]{2}$');
        SQL);
    }
};
