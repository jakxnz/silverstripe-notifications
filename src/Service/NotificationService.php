<?php

namespace Symbiote\Notifications\Service;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ArrayList;
use Symbiote\Notifications\Job\SendNotificationJob;
use Symbiote\Notifications\Sender\NotificationSender;
use Symbiote\Notifications\Model\SystemNotification;
use Symbiote\Notifications\Model\SystemNotificationTrace;
use Symbiote\Notifications\Exception\NotificationServiceException;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * NotificationService
 * @author  marcus@symbiote.com.au, shea@livesource.co.nz
 * @license http://silverstripe.org/bsd-license/
 */
class NotificationService
{
    use Configurable;

    /**
     * The list of channels to send to by default
     * e.g ['Channel' => 'NotificationSender']
     * @var array
     */
    private static $default_channels = [
        'Email' => EmailNotificationSender::class,
    ];

    /**
     * Should we use the queued jobs approach to sending notifications?
     * @var Boolean
     */
    private static $use_queues = true;

    /**
     * The objects to use for actually sending a notification, indexed
     * by their channel ID
     * @var array
     */
    protected $senders;

    /**
     * The list of channels to send to
     * @var array
     */
    protected $channels;

    public function __construct()
    {
        if (!ClassInfo::exists(QueuedJobService::class)) {
            $this->config()->use_queues = false;
        }

        $this->setChannels($this->config()->get('default_channels'));
    }

    /**
     * Add a channel that this notification service should use when sending notifications
     * @param string $channel The channel to add
     * @return \Symbiote\Notifications\Service\NotificationService
     */
    public function addChannel($channel, $sender)
    {
        $this->channels[$channel] = $sender;

        return $this;
    }

    /**
     * Set the list of channels this notification service should use when sending notifications
     * @param array $channels The channels to send to
     * @return \Symbiote\Notifications\Service\NotificationService
     */
    public function setChannels($channels)
    {
        $this->channels = $channels;

        return $this;
    }

    /**
     * Get a sender for a particular channel
     * @param string $channel
     * @return NotificationSender|null
     */
    public function getSender($channel)
    {
        return isset($this->channels[$channel]) ? $this->channels[$channel] : null;
    }

    /**
     * Trigger a notification event
     * @param   SystemNotification|string $notification   Notification or identifier
     * @param   DataObject                $context
     * @param   array                     $data           Extra data for the sender
     * @param   string|null $channel
     * @return  SystemNotification $this
     */
    public function notify($notification, $context, $data = [], $channel = null)
    {
        // Check for object
        if ($notification instanceof SystemNotification) {
            $notifications = ArrayList::create([
                $notification
            ]);
        }

        // okay, lets find any notification set up with this identifier
        if (is_string($notification)) {
            $notifications = SystemNotification::get()->filter('identifier', $notification);
        }

        // Sanity check
        if (empty($notifications) || !$notifications->count()) {
            throw new NotificationServiceException(_t(
                'Symbiote\Notifications\Serivce\NotificationService.NOTIFICATIONNOTFOUND',
                'No notification(s) identified'
            ));
        }

        // Handle dispatch
        foreach ($notifications as $notification) {
            if ($notification->NotifyOnClass && $notification->NotifyOnClass != get_class($context)) {
                throw new NotificationServiceException(_t(
                    'Symbiote\Notifications\Serivce\NotificationService.CONTEXTMISMATCH',
                    'Context does not match SystemNotification\'s NotifyOnClass'
                ));
                continue;
            } else {
                $this->sendNotification($notification, $context, $data, $channel);
            }
        }

        return $this;
    }

    /**
     * Send out a notification
     * @param SystemNotification $notification The configured notification object
     * @param DataObject         $context      The context of the notification to send
     * @param array              $extraData    Any extra data to add into the notification text
     * @param string             $channel      A specific channel to send through. If not set, just
     *                                         sends to the default configured
     */
    public function sendNotification(
        SystemNotification $notification,
        DataObject $context,
        $extraData = [],
        $channel = null
    ) {
        // check to make sure that there are users to send it to. If not, we don't bother with it at all
        $recipients = $notification->getRecipients();
        if (!count($recipients)) {
            return;
        }

        // if we've got queues and a large number of recipients, lets send via a queued job instead
        if ($this->config()->get('use_queues') && count($recipients) > 5) {
            $extraData['SEND_CHANNEL'] = $channel;
            singleton(QueuedJobService::class)->queueJob(
                new SendNotificationJob(
                    $notification,
                    $context,
                    $extraData
                )
            );
        } else {
            foreach ($recipients as $user) {
                $this->sendToUser($notification, $context, $user, $extraData);
            }
        }
    }

    /**
     * Sends a notification directly to a user
     * @param SystemNotification $notification
     * @param DataObject         $context
     * @param DataObject         $user
     * @param array              $extraData
     */
    public function sendToUser(
        SystemNotification $notification,
        DataObject $context,
        $user,
        $extraData = []
    ) {
        $channel = $extraData && isset($extraData['SEND_CHANNEL']) ? $extraData['SEND_CHANNEL'] : null;
        $channels = $channel ? [$channel] : $this->channels;

        foreach ($notification->Channels() as $channel) {
            if ($channel->canSend()) {
                $channel->getSender()->sendToUser($notification, $context, $user, $extraData);
            }
        }

        $this->traceNotification($notification, $user);
    }

    /**
     * Trace the notification
     * @param  SystemNotification  $notification
     * @param  Member              $user         The recipient
     * @return NotificationService $this
     */
    public function traceNotification($notification, $user) {

        // Tracing
        if ($notification->Trace) {
            // Notification must be stored to trace
            $notification->write();

            // Check that user is real
            if ($user->exists()) {

                $trace = SystemNotificationTrace::create([
                    'SystemNotificationID' => $notification->ID,
                    'RecipientID' => $user->ID
                ]);
                $trace->write();
            }
        }

        return $this;
    }
}
