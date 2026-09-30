/**
 * Typesense Sync — example Alpine.js search component. Unsupported: copy it in and make it yours.
 *
 *     import Alpine from 'alpinejs';
 *     import typesenseSearch from './typesense/search.js';
 *     Alpine.data('typesenseSearch', typesenseSearch);
 *     Alpine.start();
 *
 * The markup is in `search.twig`. It hands the component what `craft.typesense.searchConfig()`
 * returned: the server's address, the collection's live name and a scoped search key. That key
 * can only search, only within the filter the site chose, and only until `expiresAt`. The
 * component adds nothing that widens it: Typesense ANDs any `filter_by` sent here with the
 * key's own, and a hidden or excluded field stays hidden whatever the request asks for.
 *
 * It talks to Typesense's REST API directly, with no client library. Swap in InstantSearch or
 * typesense-js if the page needs facets and widgets.
 */
export default function typesenseSearch(options = {}) {
    return {
        config: null,
        query: '',
        hits: [],
        found: 0,
        page: 1,
        perPage: options.perPage ?? 10,
        queryBy: options.queryBy ?? 'title,summary,keywords',
        filterBy: options.filterBy ?? '',
        sortBy: options.sortBy ?? '',
        loading: false,
        // 'unavailable' (no config: the plugin is not set up, or the key could not be made),
        // 'expired' (the page outlived its key; a reload makes a new one) or 'failed'.
        error: null,
        ticket: 0,

        init() {
            try {
                this.config = JSON.parse(this.$el.dataset.typesenseConfig || 'null');
            } catch (e) {
                this.config = null;
            }

            if (!this.config) {
                this.error = 'unavailable';
            }
        },

        get pages() {
            return Math.max(1, Math.ceil(this.found / this.perPage));
        },

        async search(page = 1) {
            if (!this.config) {
                return;
            }

            // A page served from a cache can outlive its key. Say so rather than failing.
            if (this.config.expiresAt && Date.now() / 1000 >= this.config.expiresAt) {
                this.error = 'expired';
                return;
            }

            const q = this.query.trim();

            if (q === '') {
                this.hits = [];
                this.found = 0;
                this.error = null;
                return;
            }

            // Only the latest search may write its results: an earlier, slower one must not
            // overwrite them when it lands.
            const ticket = ++this.ticket;
            this.loading = true;
            this.error = null;

            const params = new URLSearchParams({
                q,
                query_by: this.queryBy,
                page: String(page),
                per_page: String(this.perPage),
                highlight_full_fields: 'title',
            });

            if (this.filterBy) {
                params.set('filter_by', this.filterBy);
            }

            if (this.sortBy) {
                params.set('sort_by', this.sortBy);
            }

            const { protocol, host, port, collection, apiKey } = this.config;
            const url = `${protocol}://${host}:${port}/collections/${encodeURIComponent(collection)}/documents/search?${params}`;

            try {
                const response = await fetch(url, { headers: { 'X-TYPESENSE-API-KEY': apiKey } });

                if (ticket !== this.ticket) {
                    return;
                }

                if (response.status === 401 || response.status === 403) {
                    this.error = 'expired';
                    return;
                }

                if (!response.ok) {
                    throw new Error(`Typesense answered ${response.status}`);
                }

                const body = await response.json();
                this.hits = (body.hits ?? []).map((hit) => ({
                    ...hit.document,
                    // Highlighted title, HTML-escaped by Typesense apart from its <mark> tags.
                    highlight: hit.highlight?.title?.snippet ?? null,
                }));
                this.found = body.found ?? 0;
                this.page = page;
            } catch (e) {
                if (ticket === this.ticket) {
                    this.error = 'failed';
                    this.hits = [];
                    this.found = 0;
                }
            } finally {
                if (ticket === this.ticket) {
                    this.loading = false;
                }
            }
        },
    };
}
