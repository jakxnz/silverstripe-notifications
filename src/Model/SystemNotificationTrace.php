<?php

namespace Symbiote\Notifications\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use Symbiote\Notifications\Model\SystemNotification;

/**
 * A record of notifications that have been dispatched
 *
 * @author jackson@codecraft.nz
 * @license http://silverstripe.org/bsd-license/
 */
class SystemNotificationTrace extends DataObject
{
    private static $table_name = 'SystemNotificationTrace';

    private static $db = [
        'Read' => 'Boolean'
    ];

    private static $has_one = [
        'SystemNotification' => SystemNotification::class,
        'Recipient' => Member::class
    ];
}
