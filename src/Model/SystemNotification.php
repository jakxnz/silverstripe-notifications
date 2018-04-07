<?php

namespace Symbiote\Notifications\Model;

use Exception;
use ReflectionClass;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\PermissionRole;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\ArrayData;
use SilverStripe\View\SSViewer;
use SilverStripe\View\SSViewer_FromString;
use SilverStripe\View\ViewableData;
use Symbiote\Notifications\Model\NotificationChannel;
use Symbiote\Notifications\Model\NotificationRecipientsMapping;
use Symbiote\Notifications\Service\NotificationService;

/**
 * SystemNotification
 * @author  marcus@symbiote.com.au, shea@livesource.co.nz
 * @license http://silverstripe.org/bsd-license/
 * @property string Identifier
 * @property string Title
 * @property string Description
 * @property string NotificationText
 * @property string NotificationHTML
 * @property string NotifyOnClass
 * @property string CustomTemplate
 * @property string Trace
 *
 * @method HasManyList Channels() List of NotificationChannels
 * @method ManyManyList RecipientMembers()
 * @method ManyManyList RecipientGroups()
 * @method ManyManyList RecipientRoles()
 *
 */
class SystemNotification extends DataObject implements PermissionProvider
{
    private static $table_name = 'SystemNotification';

    /**
     * A list of all the notifications that the system manages.
     * @var array
     */
    private static $identifiers = [];

    /**
     * A list of globally available keywords for all NotifiedOn implementors
     * @var array
     */
    private static $global_keywords = [];

    /**
     * If true, notification text can contain html and a wysiwyg editor will be
     * used to create the notification text rather than textarea
     * @var boolean
     */
    private static $html_notifications = false;

    private static $db = [
        'Identifier' => 'Varchar',        // used to reference this notification from code
        'Title' => 'Varchar(255)',
        'Description' => 'Text',
        'NotificationText' => 'Text',
        'NotificationHTML' => 'HTMLText',
        'NotifyOnClass' => 'Varchar(128)',
        'CustomTemplate' => 'Varchar',
        'Trace' => 'Boolean',
        'SendToEveryone' => 'Boolean'
    ];

    private static $has_many = [
        'Channels' => NotificationChannel::class
    ];

    private static $many_many = [
        'RecipientMembers' => Member::class,
        'RecipientGroups' => Group::class,
        'RecipientRoles' => PermissionRole::class
    ];

    /**
     * @uses    DataObject::populateDefaults()
     * @return  SystemNotification $this
     */
    public function populateDefaults()
    {
        // Must have an identifier
        if (!$this->Identifier) {
            $this->Identifier = sprintf('%s_%s', (new \ReflectionClass($this))->getShortName(), $this->ID);
        }

        // Assign channels and standard templates
        $channels = singleton(NotificationService::class)->config()->get('channels');

        // Sanity check
        if (!empty($channels) && is_array($channels)) {
            foreach ($channels as $stub => $sender) {
                $channel = NotificationChannel::create([
                    'Channel' => $stub,
                    'Template' => $sender
                ]);
                $this->Channels()->add($channel);
            }
        }

        return parent::populateDefaults();
    }

    /**
     * @uses    DataObject::validate()
     * @return  ValidationResult
     */
    public function validate()
    {
        $result = parent::validate();

        if (!$this->Identifier) {
            $result->addError('Notification must have an identifier');
        }

        return $result;
    }

    /**
     * @return FieldList
     */
    public function getCMSFields()
    {
        // Get NotifiedOn implementors
        $types = ClassInfo::implementorsOf(NotifiedOn::class);
        $types = array_combine($types, $types);
        unset($types['NotifyOnThis']);
        if (!$types) {
            $types = [];
        }
        array_unshift($types, '');

        // Available keywords
        $keywords = $this->getKeywords();
        if (count($keywords)) {
            $availableKeywords = '<div class="field">'.
                '<div class="middleColumn">'.
                    '<p><u>Available Keywords:</u></p>'.
                    '<ul>'.
                        '<li>$'.implode('</li><li>$', $keywords).'</li>'.
                    '</ul>'.
                '</div></div>';
        } else {
            $availableKeywords = "Available keywords will be shown if you select a NotifyOnClass";
        }

        // Identifiers
        $identifiers = $this->config()->get('identifiers');
        if (count($identifiers)) {
            $identifiers = array_combine($identifiers, $identifiers);
        }

        $fields = FieldList::create();

        $relevantMsg = 'Relevant for (note: this notification will only be sent if the '.
                       'context of raising the notification is of this type)';
        $fields->push(
            TabSet::create(
                'Root',
                Tab::create(
                    'Main',
                    DropdownField::create(
                        'Identifier',
                        _t('SystemNotification.IDENTIFIER', 'Identifier'),
                        $identifiers
                    ),
                    TextField::create('Title', _t('SystemNotification.TITLE', 'Title')),
                    TextField::create(
                        'Description',
                        _t('SystemNotification.DESCRIPTION', 'Description')
                    ),
                    DropdownField::create(
                        'NotifyOnClass',
                        _t('SystemNotification.NOTIFY_ON_CLASS', $relevantMsg),
                        $types
                    ),
                    TextField::create(
                        'CustomTemplate',
                        _t(
                            'SystemNotification.TEMPLATE',
                            'Template (Optional)'
                        )
                    )->setAttribute(
                        'placeholder',
                        $this->config()->get('default_template')
                    ),
                    LiteralField::create('AvailableKeywords', $availableKeywords)
                )
            )
        );

        if ($this->config()->html_notifications) {
            $fields->insertBefore(
                'AvailableKeywords',
                HTMLEditorField::create(
                    'NotificationHTML',
                    _t('SystemNotification.TEXT', 'Text')
                )
            );
        } else {
            $fields->insertBefore(
                'AvailableKeywords',
                TextareaField::create(
                    'NotificationText',
                    _t('SystemNotification.TEXT', 'Text')
                )
            );
        }

        $this->extend('updateCMSFields', $fields);

        return $fields;
    }

    /**
     * Get a list of available keywords to help the cms user know what's available
     * @return array
     **/
    public function getKeywords()
    {
        $keywords = [];

        foreach ($this->config()->get('global_keywords') as $k => $v) {
            $keywords[] = '<strong>'.$k.'</strong>';
        }

        if ($this->NotifyOnClass) {
            $dummy = singleton($this->NotifyOnClass);
            if ($dummy instanceof NotifiedOn || method_exists($dummy, 'getAvailableKeywords')) {
                if (is_array($dummy->getAvailableKeywords())) {
                    foreach ($dummy->getAvailableKeywords() as $keyword => $desc) {
                        $keywords[] = '<strong>'.$keyword.'</strong> - '.$desc;
                    }
                }
            }
        }

        return $keywords;
    }

    /**
     * Add a recipient
     * @param   Member|Group|PermissionRole $recipient
     * @return  SystemNotification          $this
     */
    public function addRecipient($recipient)
    {

        if ($recipient instanceof Member) {
            $this->RecipientMembers()->add($recipient);
        }

        if ($recipient instanceof Group) {
            $this->RecipientGroups()->add($recipient);
        }

        if ($recipient instanceof PermissionRole) {
            $this->RecipientRoles()->add($recipient);
        }

        return $this;
    }

    /**
     * Get a list of recipients from the notification with the given context
     * @return SS_List
     */
    public function getRecipients()
    {

        $recipients = ArrayList::create();
        $ids = [];

        // Check for send to everyone
        if ($this->SendToEveryone) {
            $recipients = Member::get();
        } else {


            // Send to members
            $ids = array_merge($ids, array_keys($this->RecipientMembers()->map()->toArray()));

            // Send to groups
            foreach ($this->RecipientGroups() as $group) {
                $ids = array_merge($ids, array_keys($group->Members()->map()->toArray()));
            }

            // Send to roles
            foreach ($this->RecipientRoles() as $role) {
                foreach ($role->Groups() as $group) {
                    $ids = array_merge($ids, array_keys($group->Members()->map()->toArray()));
                }
            }

            if (count($ids)) {
                $recipients = Member::get()->filter('ID', array_unique($ids));
            }
        }

        $this->extend('updatedRecipients', $recipients);

        return $recipients;
    }

    /**
     * Format text with given keywords etc
     * @param  string     $unformatted
     * @param  ViewableData $context
     * @param  Member     $user
     * @param  array      $extraData
     * @return string
     */
    public function format($unformatted, ViewableData $context, $user = null, $extraData = [])
    {
        // render
        $viewer = new SSViewer_FromString($unformatted);
        try {
            $string = $viewer->process($context->customise($extraData));
        } catch (Exception $e) {
            $string = $unformatted;
        }

        return $string;
    }

    /**
     * Set the template for a mysqlnd_qc_change_handler
     * @param    string             $channel The channel
     * @param    string             $template The template (not including .ss extension)
     * @return   SystemNotification $this
     */
    public function setChannelTemplate($channel, $template)
    {
        $channel = $this->Channels()->filter('Channel', $channel)->first();

        if ($channel) {
            $channel->Template = $template;
            $channel->write();
        } else {
            user_error(
                _t(
                    'Symbiote\Notifications\Model\SystemNotification.INVALIDCHANNEL',
                    'Channel is not assigned to this notification'
                ),
                E_WARNING
            );
        }

        return $this;
    }

    /**
     * Toggle if resulting notifications are traced. Does not write.
     * @return  SystemNotification $this
     */
    public function setTracing($bool) {
        $this->Trace = $bool;
        return $this;
    }

    /**
     * Toggle sending the notification to everyone. Does not write.
     * @return  SystemNotification $this
     */
    public function setSendToEveryone($bool) {
        $this->SendToEveryone = $bool;
        return $this;
    }

    /**
     * Get the notification content, whether that's html or plain text
     * @return string
     */
    public function NotificationContent()
    {
        return $this->config()->html_notifications ? $this->NotificationHTML : $this->NotificationText;
    }

    public function canView($member = null)
    {
        return Permission::check('ADMIN') || Permission::check('SYSTEMNOTIFICATION_VIEW');
    }

    public function canEdit($member = null)
    {
        return Permission::check('ADMIN') || Permission::check('SYSTEMNOTIFICATION_EDIT');
    }

    public function canDelete($member = null)
    {
        return Permission::check('ADMIN') || Permission::check('SYSTEMNOTIFICATION_DELETE');
    }

    public function canCreate($member = null, $context = array())
    {
        return Permission::check('ADMIN') || Permission::check('SYSTEMNOTIFICATION_CREATE');
    }

    public function providePermissions()
    {
        return [
            'SYSTEMNOTIFICATION_VIEW' => [
                'name' => 'View System Notifications',
                'category' => 'Notifications',
            ],
            'SYSTEMNOTIFICATION_EDIT' => [
                'name' => 'Edit a System Notification',
                'category' => 'Notifications',
            ],
            'SYSTEMNOTIFICATION_DELETE' => [
                'name' => 'Delete a System Notification',
                'category' => 'Notifications',
            ],
            'SYSTEMNOTIFICATION_CREATE' => [
                'name' => 'Create a System Notification',
                'category' => 'Notifications',
            ],
        ];
    }
}
