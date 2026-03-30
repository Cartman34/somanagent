import apiClient from './client'

export interface UiTranslationsResponse {
  domain: string
  locale: string
  translations: Record<string, string>
}

export const translationsApi = {
  list: async (keys: string[], domain = 'app'): Promise<UiTranslationsResponse> => {
    const { data } = await apiClient.get<UiTranslationsResponse>('/ui/translations', {
      params: { domain, keys },
    })

    return data
  },
}
