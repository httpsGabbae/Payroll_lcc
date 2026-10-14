// LCC Payroll — Supabase browser client (optional, for future REST use).
// The PHP app uses direct DB connections; this file is for any
// client-side Supabase queries. Replace with your framework as needed.
const SUPABASE_URL = window.LCC_SUPABASE_URL || '';
const SUPABASE_PUBLISHABLE_KEY = window.LCC_SUPABASE_PUBLISHABLE_KEY || '';
if (SUPABASE_URL && SUPABASE_PUBLISHABLE_KEY && window.supabase && window.supabase.createClient) {
  window.lccSupabase = window.supabase.createClient(SUPABASE_URL, SUPABASE_PUBLISHABLE_KEY);
}
