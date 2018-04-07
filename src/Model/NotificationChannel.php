<?php

namespace Symbiote\Notifications\Model;

use SilverStripe\ORM\DataObject;
use Symbiote\Notifications\Model\SystemNotification;
use Symbiote\Notifications\Service\NotificationService;

/**
 * The channel via which a notification will be dispatched
 *
 * @author jackson@codecraft.nz
 * @license http://silverstripe.org/bsd-license/
 *
 * @property string $Channel The channel stub
 * @property string $Template The full template namespace
 *
 * @method  SystemNotification SystemNotification() The notificaiton using this channel
 */
class NotificationChannel extends DataObject
{

    private static $table_name = 'NotificationChannel';

    private static $db = [
        'Channel' => 'Varchar(64)',
        'Template' => 'Varchar(128)'
    ];

    private static $has_one = [
        'SystemNotification' => SystemNotification::class
    ];

    /**
     * Set the channel, restricted to only those configured
     * @param  string $channel A channel from the set of configured channels
     * @return  NotificationChannel $this
     */
    public function setChannel($channel)
    {
        $available = array_keys(singleton(NotificationService::class)->get('channels'));

        if (in_array($channel, $available)) {
            $this->setField('Channel', $channel);
        } else {
            user_error(
                _t(
                    'Symbiote\Notifications\Model\Channel.INVALIDCHANNEL',
                    'Provided channel is not defined in configuration'
                ),
                E_WARNING
            );
        }

        return $this;
    }

    /**
     * @return Boolean
     */
    public function canSend()
    {
        $available = singleton(NotificationService::class)->config()->get('channels');
        return isset($available[$this->Channel]) && class_exists($available[$this->Channel]);
    }

    /**
     * @return NotificationSender|null
     */
    public function getSender()
    {
        $available = singleton(NotificationService::class)->config()->get('channels');
        return singleton($available[$this->Channel])->setChannel($this);
    }
}
