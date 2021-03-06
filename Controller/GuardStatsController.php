<?php
// Copyright (c) MetaClass Groningen 2014

namespace Metaclass\AuthenticationGuardBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\DataTransformer\DateTimeToLocalizedStringTransformer;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;

use Metaclass\AuthenticationGuardBundle\Service\UsernamePasswordFormAuthenticationGuard;
use Symfony\Component\PropertyAccess\Exception\RuntimeException;
use Symfony\Component\HttpFoundation\Request;

class GuardStatsController extends Controller {

    /** @var int \IntlDateFormatter datetype derived by ::initDateFormatAndPattern,
     *    Defaults to DateTimeType::DEFAULT_DATE_FORMAT */
    protected $dateFormat;
    /** @var string|null \IntlDateFormatter pattern derived by ::initDateFormatAndPattern  */
    protected $dateTimePattern;

    // Cacheing

    /** @var \Symfony\Component\Form\Extension\Core\DataTransformer\DateTimeToLocalizedStringTransformer */
    protected $dtTransformer;
    /** @var array with translated relative date words like 'minutes', 'hours', 'days' */
    protected $translateRelativeDateArray;

    /**
     * Route("/statistics", name="Guard_statistics")
     */
    public function statisticsAction(Request $request)
    {
        $this->initDateTimeTransformer();
        $governor = $this->get('metaclass_auth_guard.tresholds_governor');

        $params['title'] = $this->get('translator')->trans('statistics.title', array(), 'metaclass_auth_guard');
        $params['routes']['this'] = 'Guard_statistics';
        $params['action_params'] = array();

        $this->addStatisticCommonParams($params, $governor);
        $countingSince = $governor->getMinBlockingLimit();
        $fieldValues =& $params['fieldValues'];
        $fieldValues['countingSince'] = $this->dtTransformer->transform($countingSince);
        $fieldValues['failureCount'] = $governor->requestCountsManager->countLoginsFailed($countingSince);
        $fieldValues['successCount'] = $governor->requestCountsManager->countLoginsSucceeded($countingSince);

        $limitFrom = $request->isMethod('POST')
            ? null
            : $countingSince;
        $limits = $this->addStatsPeriodForm($params, $request, $governor, 'StatsPeriod.statistics', $limitFrom);

        if (isSet($limits['From'])) {
            $this->addCountsGroupedTableParams($params, $request, $governor, $limits);
            $params['blockedHeaderIndent'] = 6;
            $params['labels'] = array('show' => 'history.show');
            $params['route_history'] = 'Guard_history';
            $params['limits']['From'] = $this->dtTransformer->transform($limits['From']);
            $params['limits']['Until'] = $this->dtTransformer->transform($limits['Until']);
        }
        // #TODO: make params testable
        return $this->render(
            $this->container->getParameter('metaclass_auth_guard.statistics.template'),
            $params);
    }

    /**
     * Route("/history/{ipAddress}", name="Guard_history", requirements={"ipAddress" = "[^/]+"})
     */
    public function historyAction(Request $request, $ipAddress)
    {
        $this->initDateTimeTransformer();
        $governor = $this->get('metaclass_auth_guard.tresholds_governor');

        $params['routes']['this'] = 'Guard_history';
        $params['action_params'] = array('ipAddress' => $ipAddress);
        $params['title'] = $this->get('translator')->trans('history.title', array(), 'metaclass_auth_guard');
        $this->buildMenu($params, 'Guard_history');
        $params['fieldSpec'] = array(
            'IP Adres' => 'ipAddress',
            'tresholds_governor_params.limitPerUserName' => 'limitPerUserName',
            'tresholds_governor_params.limitBasePerIpAddress' => 'limitBasePerIpAddress',
        );
        $params['fieldValues'] = array(
            'ipAddress' => $ipAddress,
            'limitPerUserName' => $governor->limitPerUserName,
            'limitBasePerIpAddress' => $governor->limitBasePerIpAddress,
            );

        $limits = $this->addStatsPeriodForm($params, $request, $governor, 'StatsPeriod.history');

        if (isSet($limits['From'])) {
            $history = $governor->requestCountsManager->countsByAddressBetween($ipAddress, $limits['From'], $limits['Until']);
            $this->addHistoryTableParams($params, $history, 'username', 'secu_requests.col.username');
            $params['route_byUsername'] = 'Guard_statisticsByUserName';
            $params['labels'] = array('show' => 'history.show');
            $params['limits']['From'] = $this->dtTransformer->transform($limits['From']);
            $params['limits']['Until'] = $this->dtTransformer->transform($limits['Until']);
        }
        // #TODO: make params testable
        return $this->render(
            $this->container->getParameter('metaclass_auth_guard.statistics.template'),
            $params);
    }

    /**
     * Route("/statistics/{username}", name="Guard_statisticsByUserName", requirements={"username" = "[^/]*"})
     */
    public function statisticsByUserNameAction(Request $request, $username)
    {
        $this->initDateTimeTransformer();
        $filtered = UsernamePasswordFormAuthenticationGuard::filterCredentials(array($username, ''));
        $username = $filtered[0];
        $governor = $this->get('metaclass_auth_guard.tresholds_governor');

        $params['routes']['this'] = 'Guard_statisticsByUserName';
        $params['action_params'] = array('username' => $username);
        $params['title'] = $this->get('translator')->trans('history.title', array(), 'metaclass_auth_guard');
        $params['fieldSpec'] = array('secu_requests.username' => 'username');

        $this->addStatisticCommonParams($params, $governor, 'Guard_statisticsByUserName');

        $params['fieldSpec']['tresholds_governor_params.allowReleasedUserOnAddressFor'] = 'allowReleasedUserOnAddressFor';
        $params['fieldSpec']['statisticsByUserName.isUsernameBlocked'] = 'usernameBlocked';

        $countingSince = new \DateTime("$governor->dtString - $governor->blockUsernamesFor");
        $fieldValues =& $params['fieldValues'];
        $fieldValues['username'] = $username;
        $fieldValues['countingSince'] = $this->dtTransformer->transform($countingSince);
        $fieldValues['failureCount'] = $governor->requestCountsManager->countLoginsFailedForUserName($username, $countingSince);
        $fieldValues['successCount'] = $governor->requestCountsManager->countLoginsSucceededForUserName($username,$countingSince);
        $isUsernameBlocked = $fieldValues['failureCount'] >= $governor->limitPerUserName;
        $fieldValues['usernameBlocked'] = $this->booleanLabel($isUsernameBlocked);
        $fieldValues['allowReleasedUserOnAddressFor'] = $this->translateRelativeDate($governor->allowReleasedUserOnAddressFor);

        $limitFrom = $request->isMethod('POST') || $request->get('StatsPeriod')
            ? null
            : $countingSince;
        $limits = $this->addStatsPeriodForm($params, $request, $governor, 'Historie', $limitFrom);
        if (isSet($limits['From'])) {
            $params['labels'] = array('show' => 'history.show');
            $params['route_history'] = 'Guard_history';
            $params['limits']['From'] = $this->dtTransformer->transform($limits['From']);
            $params['limits']['Until'] = $this->dtTransformer->transform($limits['Until']);
            $history = $governor->requestCountsManager->countsByUsernameBetween($username, $limits['From'], $limits['Until']);
            $this->addHistoryTableParams($params, $history, 'ipAddress', 'secu_requests.col.ipAddress');
        }

        // #TODO: make params testable
        return $this->render(
            $this->container->getParameter('metaclass_auth_guard.statistics.template'),
            $params);
    }

    protected function addHistoryTableParams(&$params, $history, $col1Field, $col1Label)
    {
        $params['columnSpec'] = array(
            'secu_requests.col.dtFrom' => 'dtFrom',
            $col1Label => $col1Field,
            'secu_requests.col.loginsSucceeded' => 'loginsSucceeded',
            'secu_requests.col.loginsFailed' => 'loginsFailed',
            'secu_requests.col.ipAddressBlocked' => 'ipAddressBlocked',
            'secu_requests.col.usernameBlocked' => 'usernameBlocked',
            'secu_requests.col.usernameBlockedForIpAddress' => 'usernameBlockedForIpAddress',
            'secu_requests.col.usernameBlockedForCookie' => 'usernameBlockedForCookie',
        );
        forEach($history as $key => $row) {
            $dt = new \DateTime($row['dtFrom']);
            $history[$key]['dtFrom'] = $this->dtTransformer->transform($dt);
        }
        $params['items'] =  $history;
        $params['blockedHeaderIndent'] = 5;
    }

    protected function addStatsPeriodForm(&$params, $request, $governor, $label, $limitFrom=null)
    {
        $limits['Until'] = new \DateTime();
        $labels = array('From' => 'StatsPeriod.From', 'Until' => 'StatsPeriod.Until');
        $historyLimit = new \DateTime("$governor->dtString - $governor->keepCountsFor");

        $formTypeClass = $this->container->getParameter('metaclass_auth_guard.ui.StatsPeriod.formType');
        if (!class_exists($formTypeClass)) {
            throw new RuntimeException("value of metaclass_auth_guard.statistics.StatsPeriod.formType is not a class: '$formTypeClass'");
        }

        $options = array(
            'label' => $label,
            'csrf_protection' => false,
            'translation_domain' => 'metaclass_auth_guard',
            'method' => $request->getMethod(),
            // custom options defined by StatsPeriodType::configureOptions:
            'labels' => $labels,
            'min' => $historyLimit,
            'date_format' => $this->dateFormat,
            'dateTimePattern' => $this->dateTimePattern
        );
        $form = $this->createForm($formTypeClass, null, $options);

        if ($limitFrom === null) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $limits['From'] = $form->get('From')->getData();
                $limits['Until'] = $form->get('Until')->getData();
            }
        } else {
            $limits['From'] = $limitFrom;
            $form->get('From')->setData($limitFrom);
            $form->get('Until')->setData($limits['Until']);
        }
        $params['form'] = $form->createView();
        return $limits;
    }

    protected function addStatisticCommonParams(&$params, $governor)
    {
        $this->buildMenu($params, 'Guard_show');
        $fieldSpec = array(
            'tresholds_governor_params.countingSince' => 'countingSince',
            'tresholds_governor_params.blockUsernamesFor' => 'blockUsernamesFor',
            'tresholds_governor_params.blockIpAddressesFor' => 'blockIpAddressesFor',
            'secu_requests.loginsSucceeded' => 'successCount',
            'secu_requests.loginsFailed' => 'failureCount',
        );
        $params['fieldSpec'] = isSet($params['fieldSpec'])
            ? array_merge($params['fieldSpec'], $fieldSpec)
            : $fieldSpec;

        $fieldValues['blockIpAddressesFor'] = $this->translateRelativeDate($governor->blockIpAddressesFor);
        $fieldValues['blockUsernamesFor'] = $this->translateRelativeDate($governor->blockUsernamesFor);
        $params['fieldValues'] = $fieldValues;
    }

    protected function addCountsGroupedTableParams(&$params, $request, $governor, $limits)
    {
        $countsByIpAddress = $governor->requestCountsManager->countsGroupedByIpAddress($limits['From'], $limits['Until']);
        $params['columnSpec'] = array(
            'secu_requests.col.ipAddress' => 'ipAddress',
            'countsGroupedByIpAddress.col.blocked' => 'blocked',
            'countsGroupedByIpAddress.col.usernames' => 'usernames',
            'secu_requests.col.loginsSucceeded' => 'loginsSucceeded',
            'secu_requests.col.loginsFailed' => 'loginsFailed',
            'secu_requests.col.ipAddressBlocked' => 'ipAddressBlocked',
            'secu_requests.col.usernameBlocked' => 'usernameBlocked',
            'secu_requests.col.usernameBlockedForIpAddress' => 'usernameBlockedForIpAddress',
            'secu_requests.col.usernameBlockedForCookie' => 'usernameBlockedForCookie',
        );
        if ($request->isMethod('POST')) {
            unSet($params['columnSpec']['Blok']);
        }
        forEach($countsByIpAddress as $key => $row)
        {
            $blocked = $row['loginsFailed'] >= $governor->limitBasePerIpAddress;
            $countsByIpAddress[$key]['blocked'] = $this->booleanLabel($blocked);
            // Yet to be added: count usernames released, count usernames blocked

        }
        $params['items'] =  $countsByIpAddress;
    }

    /**
     * @param boolean $value
     * @return string like Yes or No
     */
    protected function booleanLabel($value)
    {
        $key = $value ? 'boolean.1' : 'boolean.0';
        return $this->get('translator')->trans($key, array(), 'metaclass_auth_guard');
    }

    protected function initDateTimeTransformer()
    {
        $this->initDateFormatAndPattern();
        $this->dtTransformer = new DateTimeToLocalizedStringTransformer(
            null,
            null,
            $this->dateFormat,
            DateTimeType::DEFAULT_TIME_FORMAT, // Compatible with DateTimeType
            \IntlDateFormatter::GREGORIAN,
            $this->dateTimePattern);
    }

    /**
     * Derives $this->dateFormat and $this->dateTimePattern from
     * parameter metaclass_auth_guard.ui.dateTimeFormat.
     * If FULL, LONG, MEDIUM or SHORT (case independent) the corresponding
     * dateformat is used. Otherwise the parameter is used as pattern.
     *
     * To be overridden by subclass if pattern depends on locale or varies otherwise
     */
    protected function initDateFormatAndPattern()
    {
        $formatOption = $this->container->getParameter('metaclass_auth_guard.ui.dateTimeFormat');
        $constantOptions = array(
            'FULL' => \IntlDateFormatter::FULL,
            'LONG' => \IntlDateFormatter::LONG,
            'MEDIUM' => \IntlDateFormatter::MEDIUM,
            'SHORT' => \IntlDateFormatter::SHORT,
        );
        $dateFormat = null;
        $formatOptionUc = strtoupper($formatOption);
        if ( isset($constantOptions[$formatOptionUc]) ) {
            $this->dateFormat = $constantOptions[$formatOptionUc];
            $this->dateTimePattern = null;
        } else {
            $this->dateFormat = DateTimeType::DEFAULT_DATE_FORMAT;
            $this->dateTimePattern = $formatOption;
        }
    }

    protected function translateRelativeDate($durationString)
    {
        $toTranslate = array('minutes', 'hours', 'days');
        $translated = $this->translateRelativeDateArray($toTranslate);
        return str_replace(
            $toTranslate,
            $translated,
            $durationString);
    }

    protected function translateRelativeDateArray($toTranslate)
    {
        if (!isset($this->translateRelativeDateArray)) {
            $t = $this->get('translator');
            $this->translateRelativeDateArray = array();
            foreach ($toTranslate as $name) {
                $this->translateRelativeDateArray[] = $t->trans('relativeDate.'.$name, array(), 'metaclass_auth_guard');
            }
        }
        return $this->translateRelativeDateArray;
    }

    protected function buildMenu(&$params, $currentRoute)
    {
        // To be overridden by subclass
    }

} 