import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { AdminGuard } from './components/AdminGuard'
import { Shell } from './components/Shell'


const Overview = lazy(() => import('./pages/Overview'))
const Login = lazy(() => import('./pages/Login'))
const Characters = lazy(() => import('./pages/Characters'))
const Data = lazy(() => import('./pages/Data'))
const Assets = lazy(() => import('./pages/Assets'))
const IndustrySlots = lazy(() => import('./pages/IndustrySlots'))
const Reprocessing = lazy(() => import('./pages/Reprocessing'))
const StationTrading = lazy(() => import('./pages/StationTrading'))
const RegionArbitrage = lazy(() => import('./pages/RegionArbitrage'))
const AssetMaterials = lazy(() => import('./pages/AssetMaterials'))
const AdminDashboard = lazy(() => import('./pages/admin/AdminDashboard'))
const AdminUsers = lazy(() => import('./pages/admin/AdminUsers'))
const AdminUserJobs = lazy(() => import('./pages/admin/AdminUserJobs'))
const AdminSponsors = lazy(() => import('./pages/admin/AdminSponsors'))
const AdminSponsorForm = lazy(() => import('./pages/admin/AdminSponsorForm'))
const AdminSettings = lazy(() => import('./pages/admin/AdminSettings'))
const AdminEsiDiagnostics = lazy(() => import('./pages/admin/AdminEsiDiagnostics'))
const MoonScan = lazy(() => import('./pages/MoonScan'))
const Planetary = lazy(() => import('./pages/Planetary'))
const FactoryPlanner = lazy(() => import('./pages/FactoryPlanner'))
const Manufacturing = lazy(() => import('./pages/Manufacturing'))
const ManufacturingBrowser = lazy(() => import('./pages/ManufacturingBrowser'))
const ReactionBrowser = lazy(() => import('./pages/ReactionBrowser'))
const Donations = lazy(() => import('./pages/Donations'))
const Projects = lazy(() => import('./pages/Projects'))
const ProjectContributors = lazy(() => import('./pages/ProjectContributors'))
const CorporateBuildPrograms = lazy(() => import('./pages/CorporateBuildPrograms'))
const BuildProgramDetail = lazy(() => import('./pages/BuildProgramDetail'))
const Dashboard = lazy(() => import('./pages/Dashboard'))
const IndustryJobs = lazy(() => import('./pages/IndustryJobs'))
const MostProfitable = lazy(() => import('./pages/MostProfitable'))
const CanProduce = lazy(() => import('./pages/CanProduce'))
const BlueprintResearch = lazy(() => import('./pages/Research').then((m) => ({ default: m.BlueprintResearch })))
const ReactionFormulaResearch = lazy(() => import('./pages/Research').then((m) => ({ default: m.ReactionFormulaResearch })))
const StockManager = lazy(() => import('./pages/StockManager'))
const BuildPrograms = lazy(() => import('./pages/BuildPrograms'))
const BuildProgram = lazy(() => import('./pages/BuildProgram'))
const MarketBrowser = lazy(() => import('./pages/MarketBrowser'))
const ContractAppraisal = lazy(() => import('./pages/ContractAppraisal'))
const ContractBrowser = lazy(() => import('./pages/ContractBrowser'))
const ContractDetail = lazy(() => import('./pages/ContractDetail'))
const Structures = lazy(() => import('./pages/Structures'))
const Reactions = lazy(() => import('./pages/Reactions'))
const ReactionPlanner = lazy(() => import('./pages/ReactionPlanner'))
const InventionPlanner = lazy(() => import('./pages/InventionPlanner'))

export function App() {
  return (
    <Suspense fallback={<p className="muted">Loading…</p>}>
      <Routes>
        {}
        <Route path="login" element={<Login />} />
        <Route element={<Shell />}>
          <Route index element={<Overview />} />
          <Route path="characters" element={<Characters />} />
          <Route path="data" element={<Data />} />
          <Route path="market-status" element={<Navigate to="/data" replace />} />
          {}
          <Route path="assets" element={<Assets key="personal" scope="personal" />} />
          <Route path="assets/corporation" element={<Assets key="corporate" scope="corporate" />} />
          <Route path="assets/materials" element={<AssetMaterials />} />
          <Route path="calculators/manufacturing" element={<Manufacturing />} />
          <Route path="calculators/manufacturing-browser" element={<ManufacturingBrowser />} />
          <Route path="calculators/reaction-browser" element={<ReactionBrowser />} />
          <Route path="calculators/reactions" element={<Reactions />} />
          <Route path="calculators/reaction-planner" element={<ReactionPlanner />} />
          <Route path="calculators/invention-planner" element={<InventionPlanner />} />
          <Route path="industry/slots" element={<IndustrySlots />} />
          <Route path="industry/reprocessing" element={<Reprocessing />} />
          <Route path="dashboard" element={<Dashboard />} />
          <Route path="industry/jobs" element={<IndustryJobs />} />
          <Route path="industry/most-profitable" element={<MostProfitable />} />
          <Route path="industry/can-produce" element={<CanProduce />} />
          <Route path="industry/blueprint-research" element={<BlueprintResearch />} />
          <Route path="industry/reaction-formula-research" element={<ReactionFormulaResearch />} />
          <Route path="industry/stock-manager" element={<StockManager />} />
          <Route path="build-programs" element={<BuildPrograms />} />
          <Route path="build-programs/:id" element={<BuildProgram />} />
          <Route path="market/station-trading" element={<StationTrading />} />
          <Route path="market/region-arbitrage" element={<RegionArbitrage />} />
          <Route path="admin" element={<AdminGuard />}>
            <Route index element={<Navigate to="dashboard" replace />} />
            <Route path="dashboard" element={<AdminDashboard />} />
            <Route path="users" element={<AdminUsers />} />
            <Route path="users/:user/industry-jobs" element={<AdminUserJobs />} />
            <Route path="sponsors" element={<AdminSponsors />} />
            <Route path="sponsors/:sponsor" element={<AdminSponsorForm />} />
            <Route path="settings" element={<AdminSettings />} />
            <Route path="esi-diagnostics" element={<AdminEsiDiagnostics />} />
          </Route>
          <Route path="tools/moon-scan" element={<MoonScan />} />
          <Route path="planetary" element={<Planetary />} />
          <Route path="planetary/factory-planner" element={<FactoryPlanner />} />
          <Route path="corporate/donations" element={<Donations />} />
          <Route path="corporate/projects" element={<Projects />} />
          <Route path="corporate/projects/contributors" element={<ProjectContributors />} />
          <Route path="corporate/build-programs" element={<CorporateBuildPrograms />} />
          <Route path="corporate/build-programs/:program" element={<BuildProgramDetail />} />
          <Route path="market/browser" element={<MarketBrowser />} />
          <Route path="tools/contract-appraisal" element={<ContractAppraisal />} />
          <Route path="tools/contract-browser" element={<ContractBrowser />} />
          <Route path="tools/contract-browser/:contractId" element={<ContractDetail />} />
          <Route path="structures" element={<Structures />} />
          <Route path="*" element={<p>Page not found.</p>} />
        </Route>
      </Routes>
    </Suspense>
  )
}
