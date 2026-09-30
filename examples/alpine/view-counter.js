/**
 * Typesense Sync — example Alpine.js view counter. Unsupported: copy it in and make it yours.
 *
 *     import typesenseViewCounter from './typesense/view-counter.js';
 *     Alpine.data('typesenseViewCounter', typesenseViewCounter);
 *
 * It posts one event, once, to a counter rule declared in `config/typesense-sync.php` (see
 * `examples/config/analytics.php`). Typesense adds the rule's weight to the document's counter
 * field, which the plugin carries through every reindex. The markup is in `view-counter.twig`,
 * which hands it `craft.typesense.analyticsConfig()`. That holds the events-only key: it can post
 * events and do nothing else.
 *
 * `user_id` is a random value made per page view and never stored, so counting sets no cookie
 * and needs no consent. A site that already has a first-party visitor id may send that instead.
 */
export default function typesenseViewCounter(rule, documentId) {
    return {
        init() {
            let config = null;

            try {
                config = JSON.parse(this.$el.dataset.typesenseAnalytics || 'null');
            } catch (e) {
                return;
            }

            const target = config?.rules?.[rule];

            if (!target || !documentId) {
                return;
            }

            const { protocol, host, port, apiKey } = config;
            const visitor = crypto.randomUUID ? crypto.randomUUID() : String(Math.random()).slice(2);

            // Fire and forget: a lost count must never break the page.
            fetch(`${protocol}://${host}:${port}/analytics/events`, {
                method: 'POST',
                keepalive: true,
                headers: { 'Content-Type': 'application/json', 'X-TYPESENSE-API-KEY': apiKey },
                body: JSON.stringify({
                    name: target.name,
                    event_type: target.eventType,
                    data: { doc_id: String(documentId), user_id: visitor },
                }),
            }).catch(() => {});
        },
    };
}
