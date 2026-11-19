<?php

test('the API host root sends visitors to the storefront (QC-076)', function () {
    $this->get('/')->assertRedirect(config('nawy.payments.storefront_url'));
});
