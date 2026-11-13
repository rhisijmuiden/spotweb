<?php

/*
 * Small JSON documents in the settings table (update check result, update
 * job). Reads and writes go straight to the table, so they also work while
 * the rest of Spotweb waits for a database upgrade.
 */
class Services_Updater_Store
{
    public static function get(dbeng_abs $conn, $name)
    {
        $json = self::getRaw($conn, $name);
        $data = $json ? json_decode($json, true) : null;

        return is_array($data) ? $data : null;
    }

    public static function getRaw(dbeng_abs $conn, $name)
    {
        try {
            $json = $conn->singleQuery('SELECT value FROM settings WHERE name = :name', [':name' => [$name, PDO::PARAM_STR]]);
        } catch (Exception $x) {
            return null;
        } // catch

        return is_string($json) ? $json : null;
    }

    public static function put(dbeng_abs $conn, $name, array $data)
    {
        $params = [':name' => [$name, PDO::PARAM_STR], ':value' => [json_encode($data), PDO::PARAM_STR]];
        $stmt = $conn->exec('UPDATE settings SET value = :value, serialized = 0 WHERE name = :name', $params);
        $updated = $stmt->rowCount();
        $stmt->closeCursor();
        if ($updated === 0) {
            $exists = $conn->singleQuery('SELECT 1 FROM settings WHERE name = :name', [':name' => [$name, PDO::PARAM_STR]]);
            if (!$exists) {
                $conn->modify('INSERT INTO settings(name, value, serialized) VALUES(:name, :value, 0)', $params);
            } // if
        } // if
    }

    /*
     * Replaces $name with $data only when it still holds $currentRaw, so
     * of several requests only one wins. Returns true when this call won.
     */
    public static function claim(dbeng_abs $conn, $name, $currentRaw, array $data)
    {
        $params = [':name' => [$name, PDO::PARAM_STR], ':old' => [(string) $currentRaw, PDO::PARAM_STR],
            ':value' => [json_encode($data), PDO::PARAM_STR], ];
        $stmt = $conn->exec('UPDATE settings SET value = :value WHERE name = :name AND value = :old', $params);
        $won = $stmt->rowCount() === 1;
        $stmt->closeCursor();

        return $won;
    }
}
