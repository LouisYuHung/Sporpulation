import { defineStore } from 'pinia';
import http from '../lib/http';

export const usePingStore = defineStore('ping', {
    state: () => ({
        message: null,
        timestamp: null,
        error: null,
        loading: false,
    }),
    actions: {
        async fetchPing() {
            this.loading = true;
            this.error = null;

            try {
                const { data } = await http.get('/ping');
                this.message = data.message;
                this.timestamp = data.timestamp;
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },
    },
});
