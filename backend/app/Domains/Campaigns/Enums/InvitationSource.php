<?php

namespace App\Domains\Campaigns\Enums;

enum InvitationSource: string
{
    case Dispatch = 'dispatch';
    case Replacement = 'replacement';
    case InviteMore = 'invite_more';
    case Request = 'request';
    case Peer = 'peer';
    case OpenJoin = 'open_join';
}
