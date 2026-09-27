<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Address;

/**
 * The 27 federative units (UF), stored as CHAR(2). The IBGE code of a UF opens
 * the geocode of everything inside it: 31 is Minas Gerais, and 3106200, a
 * municipality of Minas Gerais, is Belo Horizonte.
 */
enum BrazilianState: string
{
    case AC = 'AC';
    case AL = 'AL';
    case AP = 'AP';
    case AM = 'AM';
    case BA = 'BA';
    case CE = 'CE';
    case DF = 'DF';
    case ES = 'ES';
    case GO = 'GO';
    case MA = 'MA';
    case MT = 'MT';
    case MS = 'MS';
    case MG = 'MG';
    case PA = 'PA';
    case PB = 'PB';
    case PR = 'PR';
    case PE = 'PE';
    case PI = 'PI';
    case RJ = 'RJ';
    case RN = 'RN';
    case RS = 'RS';
    case RO = 'RO';
    case RR = 'RR';
    case SC = 'SC';
    case SP = 'SP';
    case SE = 'SE';
    case TO = 'TO';

    public function officialName(): string
    {
        return match ($this) {
            self::AC => 'Acre',
            self::AL => 'Alagoas',
            self::AP => 'Amapá',
            self::AM => 'Amazonas',
            self::BA => 'Bahia',
            self::CE => 'Ceará',
            self::DF => 'Distrito Federal',
            self::ES => 'Espírito Santo',
            self::GO => 'Goiás',
            self::MA => 'Maranhão',
            self::MT => 'Mato Grosso',
            self::MS => 'Mato Grosso do Sul',
            self::MG => 'Minas Gerais',
            self::PA => 'Pará',
            self::PB => 'Paraíba',
            self::PR => 'Paraná',
            self::PE => 'Pernambuco',
            self::PI => 'Piauí',
            self::RJ => 'Rio de Janeiro',
            self::RN => 'Rio Grande do Norte',
            self::RS => 'Rio Grande do Sul',
            self::RO => 'Rondônia',
            self::RR => 'Roraima',
            self::SC => 'Santa Catarina',
            self::SP => 'São Paulo',
            self::SE => 'Sergipe',
            self::TO => 'Tocantins',
        };
    }

    /** Two digits: the first is the region (1 North, 2 Northeast, 3 Southeast, 4 South, 5 Center-West). */
    public function ibgeCode(): string
    {
        return match ($this) {
            self::RO => '11',
            self::AC => '12',
            self::AM => '13',
            self::RR => '14',
            self::PA => '15',
            self::AP => '16',
            self::TO => '17',
            self::MA => '21',
            self::PI => '22',
            self::CE => '23',
            self::RN => '24',
            self::PB => '25',
            self::PE => '26',
            self::AL => '27',
            self::SE => '28',
            self::BA => '29',
            self::MG => '31',
            self::ES => '32',
            self::RJ => '33',
            self::SP => '35',
            self::PR => '41',
            self::SC => '42',
            self::RS => '43',
            self::MS => '50',
            self::MT => '51',
            self::GO => '52',
            self::DF => '53',
        };
    }
}
