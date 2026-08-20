<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 由應用程式刻意拋出的 404，並帶有已在地化的訊息。
 *
 * 框架自行產生的 NotFoundHttpException（路由未匹配、route model binding 失敗）
 * 帶的是內部英文文字，因此例外處理器會把那些訊息換成通用的在地化訊息。改為拋出
 * 這個子類別，就等於標示這則訊息出自我們自己、可以安全顯示。
 */
class ResourceNotFoundException extends NotFoundHttpException {}
