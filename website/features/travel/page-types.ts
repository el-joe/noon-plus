/** Route props for pages added in WP9-B (route types are generated at build time). */
export type MarketParams<P extends Record<string, string> = Record<never, string>> = Promise<{ market: string } & P>;
export type SearchParams = Promise<Record<string, string | string[] | undefined>>;
