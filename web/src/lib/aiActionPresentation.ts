import type { AiProposedAction, DeploymentStage } from '../types'
import { DEPLOYMENT_STAGES } from '../types'

const DEPLOYMENT_STAGE_LABELS: Record<DeploymentStage, string> = {
  discovery: 'Discovery',
  integration: 'Integration',
  build: 'Build',
  validation: 'Validation',
  deployed: 'Deployed',
}

export type AiActionPresentationContext = {
  currentDeploymentStage?: DeploymentStage | null
}

export type AiActionPresentation = {
  title: string
  subtitle: string | null
}

function isDeploymentStage(stage: string): stage is DeploymentStage {
  return (DEPLOYMENT_STAGES as string[]).includes(stage)
}

export function formatDeploymentStageLabel(stage: string): string {
  if (isDeploymentStage(stage)) {
    return DEPLOYMENT_STAGE_LABELS[stage]
  }

  if (stage.length === 0) {
    return 'Unknown'
  }

  return stage.charAt(0).toUpperCase() + stage.slice(1)
}

export function formatAiActionStatus(status: string): string {
  return status.toUpperCase()
}

export function presentAiProposedAction(
  action: AiProposedAction,
  context: AiActionPresentationContext = {},
): AiActionPresentation {
  switch (action.action_type) {
    case 'update_deployment_stage':
      return presentUpdateDeploymentStageAction(action, context)
    default:
      return {
        title: 'AI proposed action',
        subtitle: null,
      }
  }
}

function presentUpdateDeploymentStageAction(
  action: AiProposedAction,
  context: AiActionPresentationContext,
): AiActionPresentation {
  const stageValue = action.payload.stage
  const targetStage = typeof stageValue === 'string' ? stageValue : null
  const targetLabel = targetStage ? formatDeploymentStageLabel(targetStage) : 'Unknown'
  const currentStage = context.currentDeploymentStage

  const subtitle =
    currentStage && isDeploymentStage(currentStage) && targetStage && isDeploymentStage(targetStage)
      ? `${formatDeploymentStageLabel(currentStage)} → ${formatDeploymentStageLabel(targetStage)}`
      : `Target stage: ${targetLabel}`

  return {
    title: 'Deployment stage change',
    subtitle,
  }
}

export function presentAiActionConfirmMessage(
  action: AiProposedAction,
  type: 'approve' | 'reject',
  context: AiActionPresentationContext = {},
): string {
  const presentation = presentAiProposedAction(action, context)

  if (type === 'approve') {
    return `This will execute "${presentation.title}" immediately.`
  }

  return `This will reject "${presentation.title}" and it will not be executed.`
}
