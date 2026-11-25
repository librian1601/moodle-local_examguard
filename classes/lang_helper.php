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
 * Independent language resolver and string helper for local_examguard.
 *
 * Allows individual quizzes to serve ExamGuard alerts and overlays in Bahasa
 * Indonesia or English regardless of Moodle's active UI language or whether an
 * Indonesian language pack is installed in moodledata/lang/.
 *
 * @package    local_examguard
 * @copyright  2026 Librian Yudhista Pambudi (https://librian.my.id)
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_examguard;

defined('MOODLE_INTERNAL') || die();

/**
 * Independent language resolver and string loader.
 */
class lang_helper {

    /** @var array<string, array<string, string>> In-memory cache of loaded language strings. */
    protected static array $cachedstrings = [];

    /**
     * Resolve the active language code for a quiz attempt.
     *
     * Whitelist: 'id', 'en', 'auto'. Fallback to global defaultlang or 'id'.
     *
     * @param string|null $quizlang per-quiz language setting.
     * @return string 'id', 'en', or 'auto'.
     */
    public static function resolve_lang(?string $quizlang): string {
        $allowed = ['id', 'en', 'auto'];
        if ($quizlang !== null && in_array($quizlang, $allowed, true)) {
            return $quizlang;
        }

        $default = get_config('local_examguard', 'defaultlang');
        if ($default !== false && in_array($default, $allowed, true)) {
            return (string) $default;
        }

        return 'id';
    }

    /**
     * Get a localized string for a specific language without depending on installed language packs.
     *
     * @param string $identifier string key.
     * @param string $lang language code ('id', 'en', or 'auto').
     * @param mixed $a optional placeholder substitution data.
     * @return string translated string with placeholders replaced.
     */
    public static function get_string(string $identifier, string $lang = 'id', $a = null): string {
        global $CFG;

        if ($lang === 'auto') {
            return get_string($identifier, 'local_examguard', $a);
        }

        $lang = ($lang === 'en') ? 'en' : 'id';

        if (!isset(self::$cachedstrings[$lang])) {
            self::$cachedstrings[$lang] = [];
            $langfile = $CFG->dirroot . '/local/examguard/lang/' . $lang . '/local_examguard.php';
            if (!file_exists($langfile)) {
                $langfile = dirname(__DIR__) . '/lang/' . $lang . '/local_examguard.php';
            }
            if (file_exists($langfile)) {
                $string = [];
                include($langfile);
                self::$cachedstrings[$lang] = $string;
            }
        }

        if (isset(self::$cachedstrings[$lang][$identifier])) {
            $text = self::$cachedstrings[$lang][$identifier];
            if (is_object($a) || is_array($a)) {
                $data = (array) $a;
                foreach ($data as $key => $val) {
                    if (is_scalar($val)) {
                        $text = str_replace('{$a->' . $key . '}', (string) $val, $text);
                    }
                }
            } else if (is_scalar($a) && $a !== null) {
                $text = str_replace('{$a}', (string) $a, $text);
            }
            return $text;
        }

        // Fallback to Moodle core string retrieval if key not in array.
        return get_string($identifier, 'local_examguard', $a);
    }
}
