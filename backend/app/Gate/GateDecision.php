<?php

namespace App\Gate;

enum GateDecision: string
{
    /**
     * 取走了一個 token。
     *
     * 這個請求如果最後沒有真的佔到名額，**必須**呼叫 release() 還回去 —— 少還一次，
     * 閘門就永遠比實際嚴格一格，而那是唯一會誤殺使用者的漂移方向。
     */
    case Admitted = 'admitted';

    /** 閘門判斷已經沒有名額。請求連 MySQL 都不會抵達 —— 這就是削峰。 */
    case Shed = 'shed';

    /**
     * 閘門無法判斷：Redis 不可達、功能被關掉、或建立失敗。放行，但**沒有** token，
     * 所以絕對不能歸還。
     *
     * fail-open 是唯一合理的選擇：閘門是最佳化，不是正確性的來源。它自己故障時該
     * 讓路，讓 MySQL 照常裁決 —— 這跟限流與去重的立場一致。
     */
    case Unknown = 'unknown';

    public function consumedToken(): bool
    {
        return $this === self::Admitted;
    }
}
