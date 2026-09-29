<?php

function send_login_otp(string $phone, string $code): void
{
    throw new LogicException('No SMS provider is configured.');
}
