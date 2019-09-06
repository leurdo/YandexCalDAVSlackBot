<?php

error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once('simpleCalDAV/SimpleCalDAVClient.php');
require_once('config.php');

class CalDAVSlackBot
{
    private $user;
    private $pass;
    private $webhook;

    function __construct($user, $pass, $webhook) {
        $this->user = $user;
        $this->pass = $pass;
        $this->webhook = $webhook;
    }

    function run() {
        try {
            $this->process_calendar();
        }

        catch (Exception $e) {
            $data = $e->__toString().PHP_EOL;
            $fp = fopen('log.txt', 'a');
            fwrite($fp, $data);
        }
    }

    function process_calendar() {
        $client = new SimpleCalDAVClient();

        $data = [
            'text' => 'this content will be ignored when a block exists',
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => '*Запланированные мероприятия в твоем календаре*'
                    ]
                ],
                [
                    'type' => 'divider',
                ],
            ],
        ];

        $client->connect('https://caldav.yandex.ru', $this->user, $this->pass);
        $calendars = $client->findCalendars(); // Returns an array of all accessible calendars on the server.
        $keys_array = array_keys($calendars);
        $events_index = $keys_array[0];

        $client->setCalendar($calendars[$events_index]);

        $filter = new CalDAVFilter("VEVENT");
        /**
         * * Arguments:
         * @param $start The starting point of the time interval. Must be in the format yyyymmddThhmmssZ and should be in
         *              	GMT. If omitted the value is set to -infinity.
         * @param $end The end point of the time interval. Must be in the format yyyymmddThhmmssZ and should be in
         *              	GMT. If omitted the value is set to +infinity.
         */

        $filter->mustOverlapWithTimerange($this->get_formatted_date(), NULL);
        $events = $client->getCustomReport($filter->toXML());

        foreach ( $events as $event ) {
            $formatted = $this->get_formatted_event($event);
            $data['blocks'][] = $this->get_event_block($formatted);
            $data['blocks'][] = [
                'type' => 'divider',
            ];
        }

        $this->send_to_slack($data);
    }

    function get_event_block($formatted) {
        $event_link = '*<' . $formatted['URL'] . '|' . $formatted['SUMMARY'] . '>*';
        $event_link .= "\n" . "*Начало:* " . $this->get_unformatted_date($formatted['DTSTART;TZID=Europe/Moscow']);
        $event_link .= "\n" . "*Окончание:* " . $this->get_unformatted_date($formatted['DTEND;TZID=Europe/Moscow']);
        $event_link .= "\n" . "*Организатор:* " . $formatted['organizer'];
        $event_link .= "\n" . "*Участники:*\n" . $formatted['attandee'];

        $block = [
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => $event_link,
            ],
            'accessory' => [
                'type' => 'image',
                'image_url' => 'https://api.slack.com/img/blocks/bkb_template_images/notifications.png',
                'alt_text' => 'calendar thumbnail',
            ]
        ];

        return $block;
    }

    function get_formatted_date() {
        $now = new DateTime('now');
        return $now->format('Ymd\T000000\Z');
    }

    function get_unformatted_date($date) {

        $year = substr($date, 0, 4);
        $month = substr($date, 4, 2);
        $day = substr($date, 6, 2);
        $hours = substr($date, 9, 2);
        $minutes = substr($date, 11, 2);

        return $day . '.' . $month . '.' . $year . ' ' . $hours . 'ч ' . $minutes . 'мин';

    }

    function get_formatted_event($event) {
        $data = $event->getData();
        $arr = explode("\n", $data);
        $attendee = '';
        $organizer = '';

        foreach ( $arr as $item ) {

            if ( strpos($item, 'ORGANIZER') !== false ) {
                $organizer = substr($item, 13);
                $organizer = $this->get_org_string($organizer);
            }
            if ( strpos($item, 'ATTENDEE') !== false ) {
                $att_string = substr($item, 9);
                $att_array = explode(';', $att_string);
                $status = str_replace('PARTSTAT=', '', $att_array[0]);
                $att_link_string = str_replace('CN=', '', $att_array[1]);
                $att_link_arr = explode(':', $att_link_string);
                $att_link = '<mailto:' . $att_link_arr[2] . '|' . $att_link_arr[0] . '>';

                $attendee .= "• " . $att_link . ", " . $status . "\n";
            }

            $item_arr = explode(':', $item);
            $value = strstr($item, ':');
            $value = ltrim($value, $value[0]);
            $formatted[$item_arr[0]] = $value;
            $formatted['organizer'] = $organizer;
            $formatted['attandee'] = $attendee;
        }

        return $formatted;
    }

    function get_org_string($organizer) {
        $arr = explode(':', $organizer);
        return '<mailto:' . $arr[2] . '|' . $arr[0] . '>';
    }

    function send_to_slack($data) {
        try {
            $json = json_encode($data);

            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_URL => $this->webhook,
                CURLOPT_USERAGENT => 'cURL Request',
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => array('payload' => $json),
            ));
            $result = curl_exec($curl);

            if (!$result) {
                return false;
            }

            curl_close($curl);

            if ($result == 'ok') {
                return true;
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }
}

if ( isset($users) && is_array($users) ) {
    foreach ( $users as $user ) {
        (new CalDAVSlackBot($user['user'], $user['pass'], $user['webhook']))->run();
    }
}