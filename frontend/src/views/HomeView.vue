<script setup>
import { onMounted } from 'vue';
import { usePingStore } from '../stores/ping';

const ping = usePingStore();

onMounted(() => {
    ping.fetchPing();
});
</script>

<template>
    <section>
        <h2>後端連線狀態</h2>

        <p v-if="ping.loading">連線中...</p>
        <p v-else-if="ping.error">連線失敗：{{ ping.error }}</p>
        <p v-else-if="ping.message">
            {{ ping.message }}（{{ ping.timestamp }}）
        </p>
    </section>
</template>
