<?php

namespace App\Erp;

use RuntimeException;

/** 永久性失敗：ERP 明確拒絕這筆資料（4xx）。重送一百次結果都一樣，直接進失敗清單等人處理。 */
class ErpPermanentException extends RuntimeException {}
