<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

final class ChannelCategory
{
    public const OFFICIAL_MEDIA = 'official_media';
    public const INFLUENCER = 'influencer';
    public const B2B = 'b2b';

    private function __construct()
    {
    }
}
