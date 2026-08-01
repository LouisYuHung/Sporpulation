<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * 由應用程式刻意拋出的 409：請求格式無誤，但在與資源目前狀態的競爭中落敗。
 *
 * 子類別會帶上已在地化的訊息以及一組固定的機器碼，讓用戶端不必解析顯示文字就能
 * 依結果分支處理。
 */
abstract class ConflictException extends ConflictHttpException
{
    /**
     * 這個結果的固定識別碼，會以 `code` 的形式回傳於回應中。
     */
    abstract public function errorCode(): string;
}
