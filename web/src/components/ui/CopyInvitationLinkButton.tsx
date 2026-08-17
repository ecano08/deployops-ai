import { useState } from 'react'
import { Check, Copy } from 'lucide-react'
import { Button } from './Button'
import { Icon } from './Icon'

type CopyInvitationLinkButtonProps = {
  url: string
  label?: string
}

export function CopyInvitationLinkButton({
  url,
  label = 'Copy invitation link',
}: CopyInvitationLinkButtonProps) {
  const [copied, setCopied] = useState(false)

  async function copy() {
    await navigator.clipboard.writeText(url)
    setCopied(true)
    window.setTimeout(() => setCopied(false), 2000)
  }

  return (
    <Button variant="secondary" size="sm" onClick={() => void copy()}>
      <Icon icon={copied ? Check : Copy} size="xs" />
      {copied ? 'Copied' : label}
    </Button>
  )
}
