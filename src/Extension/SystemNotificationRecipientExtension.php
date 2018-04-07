<?php

namespace Symbiote\Notifications\Extension;

use SilverStripe\ORM\DataExtension;
use Symbiote\Notifications\Model\SystemNotification;

/**
 * Apply this extension to utilise a model as a notificaiton recipient
 *
 * @author jackson@codecraft.nz
 * @license http://silverstripe.org/bsd-license/
 *
 * @method ManyManyList Notifications() List of SystemNotifications
 */
class SystemNotificationRecipientExtension extends DataExtension
{

    /**
     * We're using a backwards many_many through, to take advantage of the
     * polymorphic many_many
     */
    private static $belongs_many_many = [
        'NotificationSubscription' => SystemNotification::class
    ];
}
