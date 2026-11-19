/**
 * The marketplace-wide shelf, read from ?action=browse.
 *
 * Home, Categories and the search screen all need the same two things - which
 * categories exist across every shop, and the products behind them - so the call
 * and its loading state live here once. `loading` is derived from which request
 * has been answered rather than set before the fetch, so changing a filter cannot
 * leave a spinner waiting for an answer that was cancelled.
 */

import { useCallback, useEffect, useState } from 'react';
import { fetchBrowse } from './api';
import { useApp } from './state';
import type { BrowseProduct } from './types';

export function useBrowse(q = '', category = '', sort = 'popular', limit = 60) {
  const { apiUrl, categories, rememberCategories } = useApp();
  const [products, setProducts] = useState<BrowseProduct[]>([]);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState('');
  const [answered, setAnswered] = useState('');

  const key = `${apiUrl}|${q}|${category}|${sort}|${limit}`;
  const loading = answered !== key;

  // Everything below the await lands after the network answer, so mounting this
  // hook never updates state synchronously inside the effect.
  const load = useCallback(async () => {
    const res = await fetchBrowse(apiUrl, { q, category, sort, limit });
    if (res.ok) {
      setProducts(res.data.products || []);
      setTotal(res.data.total || 0);
      if (res.data.categories && res.data.categories.length) rememberCategories(res.data.categories);
      setError('');
    } else {
      setProducts([]);
      setError(res.message);
    }
    setAnswered(key);
  }, [apiUrl, q, category, sort, limit, rememberCategories, key]);

  useEffect(() => {
    (async () => {
      await load();
    })();
  }, [load]);

  return { products, total, categories, loading, error, reload: load };
}
