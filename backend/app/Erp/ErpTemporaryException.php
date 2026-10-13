<?php

namespace App\Erp;

use RuntimeException;

/** 暫時性失敗：逾時、連線失敗、5xx、429。等一下再試可能就好了。 */
class ErpTemporaryException extends RuntimeException {}
