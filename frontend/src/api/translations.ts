/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

import apiClient from './client'

/** Response shape for UI translation key lookups. */
export interface UiTranslationsResponse {
  domain: string
  locale: string
  translations: Record<string, string>
}

/** API client for loading UI translation key batches. */
export const translationsApi = {
  list: async (keys: string[], domain = 'app'): Promise<UiTranslationsResponse> => {
    const { data } = await apiClient.get<UiTranslationsResponse>('/ui/translations', {
      params: { domain, keys },
    })

    return data
  },
}
