/**
 * Translation constants for the catalog domain.
 *
 * The catalog domain covers referential/configuration data shared across the application:
 * agent actions, roles, statuses, and other quasi-immutable labels.
 *
 * Rules:
 * - Translation keys must never be built dynamically. Use the static mapping below.
 * - When adding a new AgentAction, add its entry to both AGENT_ACTION_KEY_MAP and CATALOG_TRANSLATION_KEYS.
 *
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

/** Translation domain identifier for the catalog. */
export const CATALOG_DOMAIN = 'catalog'

/**
 * Static mapping from agent action key to its catalog translation key.
 * Any action key absent from this map has no known translation.
 */
export const AGENT_ACTION_KEY_MAP = {
  'product.specify':        'agent_action.product.specify',
  'tech.plan':              'agent_action.tech.plan',
  'design.ui_mockup':       'agent_action.design.ui_mockup',
  'dev.backend.implement':  'agent_action.dev.backend.implement',
  'dev.frontend.implement': 'agent_action.dev.frontend.implement',
  'review.code':            'agent_action.review.code',
  'qa.validate':            'agent_action.qa.validate',
  'docs.write':             'agent_action.docs.write',
  'ops.configure':          'agent_action.ops.configure',
  'manual.unknown':         'agent_action.manual.unknown',
} as const satisfies Record<string, string>

/** All translation keys required from the catalog domain. */
export const CATALOG_TRANSLATION_KEYS = [
  'agent_action.product.specify',
  'agent_action.tech.plan',
  'agent_action.design.ui_mockup',
  'agent_action.dev.backend.implement',
  'agent_action.dev.frontend.implement',
  'agent_action.review.code',
  'agent_action.qa.validate',
  'agent_action.docs.write',
  'agent_action.ops.configure',
  'agent_action.manual.unknown',
  'event.label.agent_response',
  'event.label.agent_question',
  'event.label.execution_dispatched',
  'event.label.execution_redispatched',
  'event.label.execution_dispatch_error',
  'event.label.execution_started',
  'event.label.execution_pending',
  'event.label.execution_running',
  'event.label.execution_retrying',
  'event.label.execution_succeeded',
  'event.label.execution_succeeded_after_retry',
  'event.label.execution_failed',
  'event.label.execution_failed_after_retry',
  'event.label.execution_dead_letter',
  'event.label.execution_error',
  'event.label.execution_retry',
  'event.label.execution_cancelled',
  'event.label.planning_completed',
  'event.label.planning_replaced',
  'event.label.planning_parse_error',
  'event.label.branch_prepared',
  'event.label.default',
  'execution.status.pending',
  'execution.status.running',
  'execution.status.retrying',
  'execution.status.succeeded',
  'execution.status.failed',
  'execution.status.dead_letter',
  'execution.status.cancelled',
  'task.type.user_story',
  'task.type.bug',
  'task.type.task',
  'task.priority.low',
  'task.priority.medium',
  'task.priority.high',
  'task.priority.critical',
  'task.status.backlog',
  'task.status.todo',
  'task.status.in_progress',
  'task.status.done',
  'task.status.cancelled',
  'task.execution_status.pending',
  'task.execution_status.running',
  'task.execution_status.retrying',
  'task.execution_status.succeeded',
  'task.execution_status.failed',
  'task.execution_status.dead_letter',
  'task.execution_status.cancelled',
] as const

/** Union type of all known catalog translation keys. */
export type CatalogTranslationKey = typeof CATALOG_TRANSLATION_KEYS[number]

/**
 * Returns the catalog translation key for the given agent action key,
 * or null if the action is not registered in the catalog.
 */
export function lookupAgentActionCatalogKey(actionKey: string | null): CatalogTranslationKey | null {
  if (actionKey === null) return null
  const key = AGENT_ACTION_KEY_MAP[actionKey as keyof typeof AGENT_ACTION_KEY_MAP]
  return key ?? null
}
