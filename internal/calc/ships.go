package calc

const ShipCategoryID = 6

var ShipGroups = map[int]string{
	25:  "Frigate",
	27:  "Battleship",
	419: "Combat Battlecruiser",
	463: "Mining Barge",
}

var FallbackShipGroups = map[int]int{
	587:   25,
	24702: 419,
	24698: 419,
	638:   27,
	17480: 463,
	32880: 25,
}
