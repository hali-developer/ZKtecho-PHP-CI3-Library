<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
include(__DIR__.'./../third_party/zklib/ZKLib.php');

/**
 * Ignited ZK Teco Devices
 *
 * CI3 library wrapper around the ZKLib third-party class.
 * Handles connection lifecycle, config loading, and returns
 * consistent ['status' => bool, 'data' => ..., 'message' => ...]
 * responses so controllers/models don't need to deal with raw
 * ZKLib exceptions or return shapes directly.
 *
 * @package    CodeIgniter
 * @subpackage libraries
 * @category   library
 * @version    0.7
 * @author     Hammad Ali <hali35275@gmail.com>
 * @link
 */
class Zkteco extends ZKLib
{

    /** @var string */
    public $ip;

    /** @var int */
    public $port;

    /** @var int */
    public $timeout;

    /** @var bool */
    public $connected = false;

    /** @var string|null */
    public $last_error = null;

    /**
     * @param array $config Optional overrides: ip, port, timeout.
     *                      Falls back to application/config/zklib.php
     */
    public function __construct($config = array())
    {
        $this->ip = isset($config['ip'])      ? $config['ip']      : ZK_IP_ADDRESS;

        if (empty($this->ip)) {
            log_message('error', 'Zklib: no device IP configured.');
        }

        parent::__construct($this->ip);

        log_message('debug', 'Zklib Library Initialized with IP: '.$this->ip.' Port: '.$this->port);
    }

    public function __destruct()
    {
        if ($this->connected) {
            $this->disconnect_device();
        }
    }

    /* ---------------------------------------------------------------
     * Connection lifecycle
     * ------------------------------------------------------------- */

    /**
     * Connect to the device and put it into a safe state for reads
     * (disableDevice stops new punches from being recorded mid-fetch).
     *
     * @param bool $disable_device Whether to disable the device while connected
     * @return bool
     */
    public function connect_device($disable_device = true)
    {
        try {
            $ret = $this->connect();

            if (!$ret) {
                $this->connected = false;
                $this->last_error = 'Unable to connect to device at '.$this->ip.':'.$this->port;
                log_message('error', 'Zklib: '.$this->last_error);
                return false;
            }

            $this->connected = true;

            if ($disable_device) {
                $this->disableDevice();
            }

            return true;
        } catch (Exception $e) {
            $this->connected = false;
            $this->last_error = $e->getMessage();
            log_message('error', 'Zklib connect_device exception: '.$e->getMessage());
            return false;
        }
    }

    /**
     * Re-enable the device (if it was disabled) and disconnect cleanly.
     *
     * @param bool $enable_device
     * @return void
     */
    public function disconnect_device($enable_device = true)
    {
        if (!$this->connected) {
            return;
        }

        try {
            if ($enable_device) {
                $this->enableDevice();
            }
            $this->disconnect();
        } catch (Exception $e) {
            log_message('error', 'Zklib disconnect_device exception: '.$e->getMessage());
        } finally {
            $this->connected = false;
        }
    }

    /**
     * @return bool
     */
    public function is_connected()
    {
        return $this->connected;
    }

    /* ---------------------------------------------------------------
     * Device info
     * ------------------------------------------------------------- */

    /**
     * @return array{status: bool, data?: array, message?: string}
     */
    public function get_device_info()
    {
        if (!$this->connected) {
            return $this->fail('Device not connected.');
        }

        try {
            $info = array(
                'version'          => $this->version(),
                'os_version'       => $this->osVersion(),
                'platform'         => $this->platform(),
                'fm_version'       => $this->fmVersion(),
                'work_code'        => $this->workCode(),
                'ssr'              => $this->ssr(),
                'pin_width'        => $this->pinWidth(),
                'face_function_on' => $this->faceFunctionOn(),
                'serial_number'    => $this->serialNumber(),
                'device_name'      => $this->deviceName(),
                'device_time'      => $this->getTime(),
            );

            return $this->ok($info);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * Synchronize device clock to server time (or a given time).
     *
     * @param string|null $datetime e.g. '2026-07-08 10:00:00'
     * @return array
     */
    public function sync_time($datetime = null)
    {
        if (!$this->connected) {
            return $this->fail('Device not connected.');
        }

        try {
            $datetime = $datetime ?: date('Y-m-d H:i:s');
            $this->setTime($datetime);
            return $this->ok(array('device_time' => $datetime));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /* ---------------------------------------------------------------
     * Users
     * ------------------------------------------------------------- */

    /**
     * Fetch all users on the device.
     *
     * @param bool $key_by_userid If true, returns array keyed by userid instead of a flat list
     * @return array
     */
    public function get_users($key_by_userid = true)
    {
        if (!$this->connected) {
            return $this->fail('Device not connected.');
        }

        try {
            $users = $this->getUser();

            if (!$key_by_userid) {
                return $this->ok($users);
            }

            $keyed = array();
            foreach ($users as $user) {
                $keyed[$user['userid']] = $user;
            }

            return $this->ok($keyed);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * Create or update a user on the device.
     *
     * @param int    $uid      Internal device slot id
     * @param string $userid   External/employee id
     * @param string $name
     * @param string $password
     * @param int    $role     ZK\Util::LEVEL_USER / LEVEL_ADMIN
     * @return array
     */
    public function sync_user($uid, $userid, $name, $password = '', $role = null)
    {
        if (!$this->connected) {
            return $this->fail('Device not connected.');
        }

        try {
            $role = $role !== null ? $role : ZK\Util::LEVEL_USER;
            $this->setUser($uid, $userid, $name, $password, $role);
            return $this->ok(array('uid' => $uid, 'userid' => $userid));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * @param int $uid
     * @return array
     */
    public function delete_user($uid)
    {
        if (!$this->connected) {
            return $this->fail('Device not connected.');
        }

        try {
            $this->removeUser($uid);
            return $this->ok(array('uid' => $uid));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * @return array
     */
    public function clear_all_users()
    {
        if (!$this->connected) {
            return $this->fail('Device not connected.');
        }

        try {
            $this->clearUsers();
            return $this->ok();
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * @return array
     */
    public function clear_admins()
    {
        if (!$this->connected) {
            return $this->fail('Device not connected.');
        }

        try {
            $this->clearAdmin();
            return $this->ok();
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /* ---------------------------------------------------------------
     * Attendance
     * ------------------------------------------------------------- */

    /**
     * Fetch attendance punches from the device.
     *
     * @param array $options {
     *     @var bool     $map_user_names Resolve id => name using get_users() (default true)
     *     @var string|null $from_date   Optional 'Y-m-d' lower bound (inclusive)
     *     @var string|null $to_date     Optional 'Y-m-d' upper bound (inclusive)
     *     @var bool     $newest_first   Sort newest first (default true)
     * }
     * @return array
     */
    public function get_attendance_logs($options = array())
    {
        if (!$this->connected) {
            return $this->fail('Device not connected.');
        }

        $map_user_names = isset($options['map_user_names']) ? $options['map_user_names'] : true;
        $from_date       = isset($options['from_date']) ? $options['from_date'] : null;
        $to_date         = isset($options['to_date']) ? $options['to_date'] : null;
        $newest_first    = isset($options['newest_first']) ? $options['newest_first'] : true;

        try {
            $users = array();
            if ($map_user_names) {
                $users_result = $this->get_users(true);
                if ($users_result['status']) {
                    $users = $users_result['data'];
                }
            }

            $attendance = $this->getAttendance();

            if (empty($attendance)) {
                return $this->ok(array());
            }

            $rows = array();
            foreach ($attendance as $item) {
                $ts = strtotime($item['timestamp']);
                $date = date('Y-m-d', $ts);

                if ($from_date !== null && $date < $from_date) {
                    continue;
                }
                if ($to_date !== null && $date > $to_date) {
                    continue;
                }

                $rows[] = array(
                    'uid'       => $item['uid'],
                    'id'        => $item['id'],
                    'name'      => isset($users[$item['id']]) ? $users[$item['id']]['name'] : $item['id'],
                    'state'     => ZK\Util::getAttState($item['state']),
                    'type'      => ZK\Util::getAttType($item['type']),
                    'date'      => $date,
                    'time'      => date('H:i:s', $ts),
                    'timestamp' => $item['timestamp'],
                );
            }

            if ($newest_first) {
                $rows = array_reverse($rows);
            }

            return $this->ok($rows);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * Clear the attendance log on the device.
     * Guards against clearing when the log is already empty, and
     * requires an explicit $confirm flag since this is destructive.
     *
     * @param bool $confirm
     * @return array
     */
    public function clear_attendance_logs($confirm = false)
    {
        if (!$this->connected) {
            return $this->fail('Device not connected.');
        }

        if (!$confirm) {
            return $this->fail('Refusing to clear attendance log without explicit confirm=true.');
        }

        try {
            $attendance = $this->getAttendance();
            if (empty($attendance)) {
                return $this->ok(array('cleared' => false, 'reason' => 'log already empty'));
            }

            $this->clearAttendance();
            return $this->ok(array('cleared' => true, 'count' => count($attendance)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /* ---------------------------------------------------------------
     * Response helpers
     * ------------------------------------------------------------- */

    /**
     * @param mixed $data
     * @return array
     */
    protected function ok($data = array())
    {
        return array(
            'status' => true,
            'data'   => $data,
        );
    }

    /**
     * @param string $message
     * @return array
     */
    protected function fail($message)
    {
        $this->last_error = $message;
        log_message('error', 'Zklib: '.$message);

        return array(
            'status'  => false,
            'data'    => array(),
            'message' => $message,
        );
    }
}