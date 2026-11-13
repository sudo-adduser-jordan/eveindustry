import { screen } from '@testing-library/react'
import { renderWithData } from '../test/render'
import EsiStatus from './EsiStatus'

const online = {
  data: { online: true, players: 23456, server_version: '2824473', vip: false },
  meta: {},
}

describe('EsiStatus', () => {
  it('shows a glowing dot and the player count when ESI is online', () => {
    const { container } = renderWithData(<EsiStatus />, [[['esi', 'status'], online]])
    expect(screen.getByRole('status')).toHaveTextContent('ESI Online · 23,456 players')
    expect(container.querySelector('.esi-dot.on')).not.toBeNull()
  })

  it('shows a red dot when ESI is unreachable', () => {
    const { container } = renderWithData(<EsiStatus />, [[['esi', 'status'], { data: { ...online.data, online: false }, meta: {} }]])
    expect(screen.getByRole('status')).toHaveTextContent('ESI unreachable')
    expect(container.querySelector('.esi-dot.off')).not.toBeNull()
  })

  it('shows a pending state while loading', () => {
    renderWithData(<EsiStatus />, [])
    expect(screen.getByRole('status')).toHaveTextContent('Checking ESI…')
  })
})
