<?php


declare(strict_types=1);


namespace Nstwf\Redlock;


final class Reply
{
    public const ACQUIRE_SUCCESS = 'OK';
    public const ACQUIRE_ERROR = '0';
    public const RELEASE_SUCCESS = '1';
    public const RELEASE_ERROR = '0';
}
