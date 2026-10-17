// Supabase Edge Function: بتبعت الإيميلات بتاعة الموقع (استعادة كلمة السر ...) عن طريق Resend.
// الاستدعاء من السيرفر بتاع الموقع فقط (PHP) وبمفتاح مشترك في الهيدر x-mail-secret.
//
// Secrets المطلوبة في Supabase (مش في الكود):
//   RESEND_API_KEY      مفتاح Resend
//   MAIL_FROM           عنوان المرسل، مثال: وفرنهالك <no-reply@your-domain.com> (لازم يكون الدومين متحقق منه في Resend)
//   MAIL_SHARED_SECRET  سلسلة عشوائية طويلة، نفس القيمة اللي في secrets.php على الاستضافة

const MAX_TO = 190;
const MAX_SUBJECT = 200;
const MAX_TEXT = 5000;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json; charset=utf-8" },
  });
}

// مقارنة بدون توقيت ثابت لتفادي تسريب طول/محتوى المفتاح
function safeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

Deno.serve(async (req: Request) => {
  if (req.method !== "POST") return json(405, { ok: false, error: "method_not_allowed" });

  const expected = Deno.env.get("MAIL_SHARED_SECRET") ?? "";
  const got = req.headers.get("x-mail-secret") ?? "";
  if (!expected || !safeEqual(got, expected)) return json(403, { ok: false, error: "forbidden" });

  const apiKey = Deno.env.get("RESEND_API_KEY") ?? "";
  const from = Deno.env.get("MAIL_FROM") ?? "";
  if (!apiKey || !from) return json(500, { ok: false, error: "mail_not_configured" });

  let payload: { to?: unknown; subject?: unknown; text?: unknown };
  try {
    payload = await req.json();
  } catch {
    return json(400, { ok: false, error: "bad_json" });
  }

  const to = typeof payload.to === "string" ? payload.to.trim() : "";
  const subject = typeof payload.subject === "string" ? payload.subject.trim() : "";
  const text = typeof payload.text === "string" ? payload.text : "";

  if (!to || to.length > MAX_TO || !EMAIL_RE.test(to)) return json(400, { ok: false, error: "bad_to" });
  if (!subject || subject.length > MAX_SUBJECT) return json(400, { ok: false, error: "bad_subject" });
  if (!text || text.length > MAX_TEXT) return json(400, { ok: false, error: "bad_text" });

  const res = await fetch("https://api.resend.com/emails", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${apiKey}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ from, to: [to], subject, text }),
  });

  if (!res.ok) {
    // ما بنرجعش تفاصيل مزود الإيميل للعميل؛ بس نسجلها في لوج Supabase
    console.error("resend_failed", res.status, await res.text());
    return json(502, { ok: false, error: "send_failed" });
  }

  return json(200, { ok: true });
});
