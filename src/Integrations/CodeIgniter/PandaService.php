<?php

namespace Panda\Integrations\CodeIgniter;

use CodeIgniter\Config\BaseService;
use Panda\Crypto\KeyProvider;
use Panda\Crypto\LocalKeyProvider;
use Panda\Crypto\FieldCipher;
use Panda\Audit\AuditLogger;
use Panda\Telemetry\TelemetryClient;

class PandaService extends BaseService
{
    /**
     * Return the FieldCipher service.
     */
    public static function fieldCipher(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('fieldCipher');
        }

        $keyProvider = new LocalKeyProvider();
        
        // CodeIgniter 4 uses Services::logger() for logging
        $ciLogger = null;
        if (class_exists('\Config\Services')) {
            try {
                $ciLogger = \Config\Services::logger();
            } catch (\Exception $e) {
                // Fail-safe if logger is not ready
            }
        }

        $auditLogger = new AuditLogger($ciLogger);
        $telemetry = new TelemetryClient();

        return new FieldCipher($keyProvider, $auditLogger, $telemetry);
    }
}
