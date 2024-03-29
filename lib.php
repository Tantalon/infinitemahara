<?php
/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    mahara
 * @subpackage export-infiniterooms
 * @author     Infinite Rooms
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL
 * @copyright  (C) 2013 Infinite Rooms
 *
 */

defined('INTERNAL') || die();

require_once(dirname(__FILE__) . '/MaharaInfiniteRoomsIntegration.php');

/**
 * Infinite Rooms plugin
 */
class PluginExportInfiniterooms extends Plugin {

    public static function get_cron() {
        $cron = new StdClass;
        $cron->callfunction = 'sync';
        $cron->minute = '0,15,30,45';
        return array($cron);
    }

    public static function sync() {
        $integration = new MaharaInfiniteRoomsIntegration();
        $integration->sync();
    }

    public static function get_title() {
        return get_string('title', 'export.infiniterooms');
    }

    public static function get_description() {
        return get_string('description', 'export.infiniterooms');
    }

}
