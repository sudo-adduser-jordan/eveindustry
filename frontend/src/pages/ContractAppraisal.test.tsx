import { screen } from '@testing-library/react'
import { me, renderWithData } from '../test/render'
import ContractAppraisal from './ContractAppraisal'

const demoMe = () => {
  const m = me([])
  return { data: { ...m.data, demo: true }, meta: m.meta }
}

describe('ContractAppraisal', () => {
  it('waits for input from regular accounts', () => {
    renderWithData(<ContractAppraisal />, [[['me'], me([])]])
    expect(screen.getByLabelText('Items')).toHaveValue('')
    expect(screen.getByText('Paste item names and press Appraise.')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('runs an example appraisal for the demo account', async () => {
    renderWithData(<ContractAppraisal />, [[['me'], demoMe()]])
    expect(await screen.findByDisplayValue(/Tritanium/)).toBeInTheDocument()
    expect(await screen.findByRole('alert')).toBeInTheDocument()
  })
})
