/** Marketer types with a public page (PORTAL_PAGES §6, QC-042). */
export const MARKETER_ROLES = ["influencer", "affiliate", "broker"] as const;
export type MarketerRole = (typeof MARKETER_ROLES)[number];

export const isMarketerRole = (value: string): value is MarketerRole => (MARKETER_ROLES as readonly string[]).includes(value);

/** Registration wizard of each role (`/join/{type}`, job type preselected with `?role=`). */
export const MARKETER_REGISTER_PATH: Record<MarketerRole, string> = {
  influencer: "/join/marketer?role=influencer",
  affiliate: "/join/marketer?role=affiliate",
  broker: "/join/broker",
};
