<?php

namespace Symbiote\Notifications\Sender;

use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\View\ViewableData;
use SilverStripe\View\SSViewer;
use Symbiote\Notifications\Sender\NotificationSender;
use Symbiote\Notifications\Model\NotificationChannel;
use Symbiote\Notifications\Model\SystemNotification;

/**
 * EmailNotificationSender
 *
 * @author  marcus@symbiote.com.au, shea@livesource.co.nz
 * @license http://silverstripe.org/bsd-license/
 */
class EmailNotificationSender implements NotificationSender
{
    use Configurable, Extensible;

    /**
     * Email Address to send email notifications from
     *
     * @var string
     */
    private static $send_notifications_from;

    private static $dependencies = [
        'logger' => '%$Psr\Log\LoggerInterface',
    ];

    /**
     * @var LoggerInterface
     */
    public $logger;

    /**
     * The current channel instance
     * @var NotificationChannel
     */
    protected $channel;

    /**
     * Set the channel instance
     *
     * @return EmailNotificationSender $this
     */
    public function setChannel(NotificationChannel $channel)
    {
        $this->channel = $channel;
        return $this;
    }

    /**
     * Send a notification via email to the selected users
     *
     * @param SystemNotification           $notification
     * @param \SilverStripe\ORM\DataObject $context
     * @param array                        $data
     */
    public function sendNotification($notification, $context, $data)
    {
        $users = $notification->getRecipients();
        foreach ($users as $user) {
            $this->sendToUser($notification, $context, $user, $data);
        }
    }

    /**
     * Send a notification directly to a single user
     *
     * @param SystemNotification $notification
     * @param $context
     * @param $user
     * @param array              $data
     */
    public function sendToUser($notification, ViewableData $context, $user, $data)
    {

        $subject = $notification->format($notification->Title, $context, $user, $data);

        // Format message
        if (Config::inst()->get(SystemNotification::class, 'html_notifications')) {
            $string = $notification->NotificationContent();
        } else {
            $string = nl2br($notification->NotificationContent());
        }
        $message = $notification->format(
            $string,
            $context,
            $user,
            $data
        );

        // Format body
        if (SSViewer::hasTemplate($this->channel->Template)) {
            if ($context instanceof ViewableData) {
                $body = $context
                    ->customise([
                        'Body' => $message
                    ])
                    ->renderWith($this->channel->Template);
            }
        } else {
            $body = $message;
        }

        // Create email
        $from = $this->config()->get('send_notifications_from');
        $to = $user->Email;
        if (!$to && method_exists($user, 'getEmailAddress')) {
            $to = $user->getEmailAddress();
        }

        // log
        $this->logger->notice(sprintf("Sending %s to %s", $subject, $to));

        try {
            // Send
            $email = new Email($from, $to, $subject);
            $email->setBody($body);
            $this->extend('onBeforeSendToUser', $email);

            $email->send();
        } catch (Exception $e) {
            // Do nothing
        }
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;

        return $this;
    }
}
