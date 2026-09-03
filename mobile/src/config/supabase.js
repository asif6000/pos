import { createClient } from '@supabase/supabase-js';
import AsyncStorage from '@react-native-async-storage/async-storage';

const SUPABASE_URL = 'https://dejghwuyczaktvivzkw.supabase.co';
const SUPABASE_ANON_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImRlamdod3V5emNhdGt0dnZpemt3Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODg0MjU1MzcsImV4cCI6MjEwNDAwMTUzN30.MGM25cSztANzuTl5DtDhI92CcGHbAQ2fXNmqGyau9NY';

export const supabase = createClient(SUPABASE_URL, SUPABASE_ANON_KEY, {
  auth: {
    storage: AsyncStorage,
    autoRefreshToken: true,
    persistSession: true,
    detectSessionInUrl: false,
  },
});
