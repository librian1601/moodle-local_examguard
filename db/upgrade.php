<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrade steps for local_examguard.
 *
 * @package    local_examguard
 * @copyright  2026 Librian Yudhista Pambudi (https://librian.my.id)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Run upgrade steps for local_examguard.
 *
 * @param int $oldversion the version we are upgrading from.
 * @return bool true on success.
 */
function xmldb_local_examguard_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026111501) {
        // Create table local_examguard_quiz for per-quiz ExamGuard settings.
        $table = new xmldb_table('local_examguard_quiz');

        $table->add_field('id',             XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('quizid',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('enabled',        XMLDB_TYPE_INTEGER, '1',  null, XMLDB_NOTNULL, null, '0');
        $table->add_field('penaltyseconds', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '120');
        $table->add_field('freezetimermax', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '600');
        $table->add_field('timecreated',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('quizid',  XMLDB_KEY_UNIQUE,  ['quizid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026111501, 'local', 'examguard');
    }

    if ($oldversion < 2026111504) {
        $table = new xmldb_table('local_examguard_quiz');
        $field = new xmldb_field('watermark', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'freezetimermax');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026111504, 'local', 'examguard');
    }

    if ($oldversion < 2026111505) {
        $table = new xmldb_table('local_examguard_quiz');
        $field = new xmldb_field('lang', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'id', 'watermark');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026111505, 'local', 'examguard');
    }

    if ($oldversion < 2026112600) {
        $table = new xmldb_table('local_examguard_quiz');
        $field = new xmldb_field('maxpenaltyseconds', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '600', 'penaltyseconds');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026112600, 'local', 'examguard');
    }

    return true;
}
