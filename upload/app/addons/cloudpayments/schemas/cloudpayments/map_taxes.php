<?php

use Tygh\Addons\RusTaxes\TaxType;

$schema = array(
    TaxType::NONE    => '',
    TaxType::VAT_0   => '0',
    TaxType::VAT_10  => '10',
    TaxType::VAT_18  => '18',
    TaxType::VAT_110 => '110',
    TaxType::VAT_118 => '118'
);

return $schema;
