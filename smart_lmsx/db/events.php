<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_viewed',
        'callback'  => '\local_smart_lmsx\observer::store_activity',
    ],
    [
        'eventname' => '\mod_forum\event\post_created',
        'callback'  => '\local_smart_lmsx\observer::store_activity',
    ],
    [
        'eventname' => '\mod_forum\event\discussion_created',
        'callback'  => '\local_smart_lmsx\observer::store_activity',
    ],
    [
        'eventname' => '\mod_assign\event\submission_graded',
        'callback'  => '\local_smart_lmsx\observer::store_activity',
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback'  => '\local_smart_lmsx\observer::store_activity',
    ],
    [
        'eventname' => '\core\event\user_graded',
        'callback'  => '\local_smart_lmsx\observer::store_activity',
    ],
];