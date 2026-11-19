import { openSync, readSync, closeSync, statSync } from "node:fs";
import path from "node:path";

/**
 * Codes are read from the Laravel mail log (MAIL_MAILER=log writes each mail
 * to storage/logs/laravel.log). Take `mailMark()` before triggering a mail,
 * then `waitForMail(email, mark)` reads only what was written after it.
 */
export const LOG_FILE = process.env.E2E_LARAVEL_LOG ?? path.resolve(__dirname, "../../../backend/storage/logs/laravel.log");

export function mailMark(): number {
  try {
    return statSync(LOG_FILE).size;
  } catch {
    return 0;
  }
}

function readFrom(offset: number): string {
  const size = statSync(LOG_FILE).size;
  if (size <= offset) return "";
  const fd = openSync(LOG_FILE, "r");
  try {
    const buffer = Buffer.alloc(size - offset);
    readSync(fd, buffer, 0, buffer.length, offset);
    return buffer.toString("utf8");
  } finally {
    closeSync(fd);
  }
}

/** Mails to `email` written after `mark`, newest last. */
export function mailsTo(email: string, mark: number): string[] {
  const text = readFrom(mark);
  const parts = text.split(/^\[[^\]]+\] \w+\.DEBUG: From: /m).slice(1);
  return parts
    .filter((mail) => new RegExp(`^To: ${email.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}\\s*$`, "mi").test(mail))
    // The log mailer writes bodies as plain text (links keep a literal "="), so only soft line breaks are undone;
    // decoding "=XX" would corrupt tokens such as "token=85…".
    .map((mail) => mail.replace(/=\r?\n/g, ""));
}

export async function waitForMail(email: string, mark: number, match?: RegExp, timeoutMs = 15_000): Promise<string> {
  const end = Date.now() + timeoutMs;
  while (Date.now() < end) {
    const found = mailsTo(email, mark).filter((m) => !match || match.test(m));
    if (found.length) return found[found.length - 1];
    await new Promise((r) => setTimeout(r, 250));
  }
  throw new Error(`No mail to ${email} in the Laravel log after offset ${mark}`);
}

/** The 6-digit code of the newest mail to `email` after `mark`. */
export async function waitForCode(email: string, mark: number): Promise<string> {
  const mail = await waitForMail(email, mark, /\b\d{6}\b/);
  const plain = mail.split(/Content-Type: text\/html/)[0];
  const body = plain.slice(Math.max(0, plain.indexOf("Content-Transfer-Encoding")));
  const code = /(?<![\w-])(\d{6})(?![\w-])/.exec(body);
  if (!code) throw new Error(`No code in mail to ${email}`);
  return code[1];
}
