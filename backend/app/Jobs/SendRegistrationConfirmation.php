<?php

namespace App\Jobs;

use App\Models\ActivityRegistration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * 報名成功後寄出確認信。
 *
 * 這件事刻意不留在請求路徑上：信寄不出去，使用者的名額還是他的。留在同步路徑只會
 * 讓報名 API 的延遲被郵件伺服器綁架 - 對方慢三秒，你的 API 就慢三秒。
 */
class SendRegistrationConfirmation implements ShouldQueue
{
    use Queueable;

    /**
     * 只帶 id，不帶整個模型、也不帶 email。
     *
     * Job 從入列到執行之間有時間差，期間報名可能已被取消、使用者可能改了 email。
     * 帶 id 的意思是「執行時再看現在的狀態」，帶快照則是「用入列當下的狀態」——
     * 對確認信來說前者才對。
     */
    public function __construct(public int $registrationId) {}

    public function handle(): void
    {
        $registration = ActivityRegistration::with(['activity', 'user'])
            ->find($this->registrationId);

        // 紀錄不見了或已經取消。這不是錯誤 - 沒有東西可以確認，安靜結束就好，
        // 不該讓它進重試迴圈然後進死信。
        if ($registration === null || ! $registration->isConfirmed()) {
            return;
        }

        Mail::raw(
            "您已成功報名「{$registration->activity->title}」。",
            fn ($message) => $message
                ->to($registration->user->email)
                ->subject('報名確認'),
        );
    }
}
