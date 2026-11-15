import { screen } from '@testing-library/react'
import { renderWithData } from '../test/render'
import AssetMaterials from './AssetMaterials'

const meta = { last_updated: '2026-10-23T12:00:00+00:00' }

describe('AssetMaterials', () => {
  it('shows groups with prices and flags unpriced stacks', () => {
    renderWithData(<AssetMaterials />, [
      [
        ['assets', 'materials'],
        {
          data: {
            grand_total: 7500,
            groups: [
              {
                name: 'Minerals',
                total: 7500,
                items: [
                  { type_id: 34, type_name: 'Tritanium', quantity: 1500, unit_price: 5, total_value: 7500 },
                  { type_id: 35, type_name: 'Pyerite', quantity: 7, unit_price: 0, total_value: 0 },
                ],
              },
            ],
          },
          meta: { ...meta, corporation_assets_warning: 'No main character is set.' },
        },
      ],
    ])

    expect(screen.getByRole('heading', { name: /Minerals/ })).toBeInTheDocument()
    expect(screen.getByText('1,500')).toBeInTheDocument()
    expect(screen.getByText('no price')).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('Corporation assets unavailable: No main character is set.')
  })

  it('explains an empty list', () => {
    renderWithData(<AssetMaterials />, [
      [['assets', 'materials'], { data: { grand_total: 0, groups: [] }, meta: { last_updated: null, corporation_assets_warning: null } }],
    ])

    expect(screen.getByText(/No materials found/)).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('Never updated')
  })
})
