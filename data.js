/* =====================================================================
   data.js — DEMO / SAMPLE DATA ONLY
   ---------------------------------------------------------------------
   Every name, GST number, bank account number and order ID in this file
   is FICTIONAL and generated for demonstration purposes. Dates are built
   relative to the day the demo is first opened, so the "Today",
   "This Week" and "This Month" filters always show data.
   ===================================================================== */

function buildSeedData() {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const iso = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const ago = n => { const x = new Date(today); x.setDate(x.getDate() - n); return iso(x); };
  let seed = 7919;
  const rnd = () => (seed = (seed * 16807) % 2147483647) / 2147483647;
  const digits = n => Array.from({ length: n }, () => Math.floor(rnd() * 10)).join('');
  const id = (p, n) => `${p}-${String(n).padStart(3, '0')}`;

  /* ---------- Partners (fictional) ---------- */
  const partners = [
    { id: id('P', 1), name: 'Rajesh Mehta', role: 'Managing Partner', phone: '+91 90000 10001', email: 'rajesh.mehta@example.com', share: 25, joined: ago(210) },
    { id: id('P', 2), name: 'Priya Sharma', role: 'Operations Partner', phone: '+91 90000 10002', email: 'priya.sharma@example.com', share: 20, joined: ago(210) },
    { id: id('P', 3), name: 'Amit Patel', role: 'Procurement Partner', phone: '+91 90000 10003', email: 'amit.patel@example.com', share: 20, joined: ago(210) },
    { id: id('P', 4), name: 'Sneha Iyer', role: 'Marketing Partner', phone: '+91 90000 10004', email: 'sneha.iyer@example.com', share: 20, joined: ago(210) },
    { id: id('P', 5), name: 'Vikram Singh', role: 'Finance Partner', phone: '+91 90000 10005', email: 'vikram.singh@example.com', share: 15, joined: ago(210) }
  ];

  /* ---------- Bank accounts (fictional) ---------- */
  const accounts = [
    { id: id('ACC', 1), name: "Partners' Joint Current A/c", type: 'Joint', bank: 'Demo National Bank', accNo: 'DEMO-XXXX-1001', ifsc: 'DEMO0001001', gstId: '', opening: 50000, openedOn: ago(215) },
    { id: id('ACC', 2), name: 'Mehta Trading Co. – Current A/c', type: 'Business', bank: 'Sample Commerce Bank', accNo: 'DEMO-XXXX-2101', ifsc: 'SMPL0002101', gstId: id('GST', 1), opening: 25000, openedOn: ago(212) },
    { id: id('ACC', 3), name: 'Sharma Lifestyle – Current A/c', type: 'Business', bank: 'Example Finance Bank', accNo: 'DEMO-XXXX-3102', ifsc: 'EXMP0003102', gstId: id('GST', 2), opening: 25000, openedOn: ago(212) },
    { id: id('ACC', 4), name: 'Patel Home Essentials – Current A/c', type: 'Business', bank: 'Mock Co-operative Bank', accNo: 'DEMO-XXXX-4103', ifsc: 'MOCK0004103', gstId: id('GST', 3), opening: 25000, openedOn: ago(212) },
    { id: id('ACC', 5), name: 'Iyer Electronics Hub – Current A/c', type: 'Business', bank: 'Sample Commerce Bank', accNo: 'DEMO-XXXX-5104', ifsc: 'SMPL0005104', gstId: id('GST', 4), opening: 25000, openedOn: ago(212) }
  ];

  /* ---------- GST registrations (fictional GSTINs) ---------- */
  const gsts = [
    { id: id('GST', 1), gstin: '24AAKPM1234A1Z5', partnerId: id('P', 1), businessName: 'Mehta Trading Co.', state: 'Gujarat', regDate: ago(214) },
    { id: id('GST', 2), gstin: '27ABHPS5678B1Z3', partnerId: id('P', 2), businessName: 'Sharma Lifestyle Store', state: 'Maharashtra', regDate: ago(214) },
    { id: id('GST', 3), gstin: '29AAGPP9012C1Z1', partnerId: id('P', 3), businessName: 'Patel Home Essentials', state: 'Karnataka', regDate: ago(213) },
    { id: id('GST', 4), gstin: '08AAIPI3456D1Z7', partnerId: id('P', 4), businessName: 'Iyer Electronics Hub', state: 'Rajasthan', regDate: ago(213) }
  ];

  /* ---------- Selling platforms ---------- */
  const platforms = [
    { id: id('PLT', 1), name: 'Flipkart', gstIds: [id('GST', 1), id('GST', 2)], commission: 12, shipPerUnit: 14, color: '#2874f0' },
    { id: id('PLT', 2), name: 'Meesho', gstIds: [id('GST', 2), id('GST', 4)], commission: 8, shipPerUnit: 10, color: '#9f2089' },
    { id: id('PLT', 3), name: 'Amazon', gstIds: [id('GST', 3), id('GST', 4)], commission: 15, shipPerUnit: 18, color: '#f59e0b' }
  ];

  /* ---------- Products ---------- */
  const P = (n, name, sku, category, purchasePrice, sellingPrice, gstRate, openingStock, reorderLevel) =>
    ({ id: id('PRD', n), name, sku, category, purchasePrice, sellingPrice, gstRate, openingStock, reorderLevel });
  const products = [
    P(1, "Women's Cotton Kurti", 'KRT-001', 'Apparel', 210, 699, 5, 40, 15),
    P(2, "Men's Round Neck T-Shirt", 'TSH-002', 'Apparel', 120, 399, 5, 60, 20),
    P(3, 'Wireless Bluetooth Earbuds', 'EAR-003', 'Electronics', 480, 1499, 18, 20, 15),
    P(4, 'Stainless Steel Bottle 1L', 'BTL-004', 'Home & Kitchen', 165, 549, 18, 30, 12),
    P(5, 'Anti-Slip Yoga Mat 6mm', 'YGM-005', 'Fitness', 260, 899, 12, 15, 8),
    P(6, 'LED Study Desk Lamp', 'LMP-006', 'Electronics', 330, 1099, 18, 18, 10),
    P(7, 'Ceramic Coffee Mug Set (4)', 'MUG-007', 'Home & Kitchen', 220, 749, 12, 20, 10),
    P(8, 'Shockproof Phone Back Cover', 'CVR-008', 'Accessories', 40, 199, 18, 100, 30),
    P(9, 'Laptop Backpack 30L', 'BAG-009', 'Bags', 390, 1299, 18, 14, 6),
    P(10, 'Airtight Kitchen Containers (6)', 'KTC-010', 'Home & Kitchen', 240, 799, 18, 18, 10)
  ];
  const prod = n => products[n - 1];
  const gstAcc = g => accounts.find(a => a.gstId === g).id;

  /* ---------- Partner investments (into the joint account) ---------- */
  const joint = id('ACC', 1);
  const invPlan = [
    [1, 'Contribution', 300000, 205, 'Initial capital contribution', true],
    [2, 'Contribution', 240000, 205, 'Initial capital contribution', true],
    [3, 'Contribution', 240000, 204, 'Initial capital contribution', true],
    [4, 'Contribution', 240000, 204, 'Initial capital contribution', true],
    [5, 'Contribution', 180000, 203, 'Initial capital contribution', true],
    [1, 'Contribution', 100000, 92, 'Additional capital for festive stock', false],
    [3, 'Contribution', 50000, 90, 'Additional capital for festive stock', false],
    [5, 'Withdrawal', 40000, 32, 'Partner drawings', false],
    [2, 'Withdrawal', 25000, 18, 'Partner drawings', false]
  ];
  const investments = invPlan.map((r, i) => ({
    id: id('INV', i + 1), partnerId: id('P', r[0]), type: r[1], amount: r[2], date: ago(r[3]), accountId: joint, desc: r[4], initial: r[5]
  }));

  /* ---------- Internal bank transfers ---------- */
  const trPlan = [
    [1, 2, 250000, 200, 'Working capital – Mehta Trading Co.'],
    [1, 3, 200000, 200, 'Working capital – Sharma Lifestyle'],
    [1, 4, 200000, 199, 'Working capital – Patel Home Essentials'],
    [1, 5, 200000, 199, 'Working capital – Iyer Electronics Hub'],
    [1, 4, 50000, 88, 'Festive stock top-up'],
    [2, 1, 40000, 20, 'Surplus returned to joint account'],
    [1, 5, 30000, 8, 'Ad budget top-up']
  ];
  const transfers = trPlan.map((r, i) => ({
    id: id('TRF', i + 1), fromId: id('ACC', r[0]), toId: id('ACC', r[1]), amount: r[2], date: ago(r[3]), ref: 'UTRDEMO' + digits(8), desc: r[4]
  }));

  /* ---------- Purchases ---------- */
  const supplierFor = {
    1: 'Surat Textile Hub (Demo)', 2: 'Tiruppur Knitwear Mills (Demo)', 3: 'Delhi Audio Imports (Demo)', 4: 'Rajkot Steelware (Demo)',
    5: 'Pune Fitness Supplies (Demo)', 6: 'Mumbai Lighting Traders (Demo)', 7: 'Khurja Ceramics (Demo)', 8: 'Delhi Mobile Accessories Mart (Demo)',
    9: 'Jaipur Bags & Co. (Demo)', 10: 'Bengaluru Home Supplies (Demo)'
  };
  const purPlan = [
    // product, qty, gst#, daysAgo, unit cost adj
    [1, 60, 2, 185, 0], [2, 80, 1, 182, 0], [3, 40, 4, 178, 0], [4, 50, 3, 170, 0], [8, 150, 1, 160, 0],
    [7, 30, 3, 150, 0], [10, 30, 3, 135, 0], [5, 25, 2, 120, 0], [1, 40, 1, 100, 5], [3, 10, 4, 88, -10],
    [2, 60, 2, 75, 3], [4, 40, 4, 60, 0], [8, 100, 2, 45, -2], [7, 20, 3, 30, 5], [10, 25, 4, 12, 0]
  ];
  const purchases = purPlan.map((r, i) => {
    const p = prod(r[0]); const g = id('GST', r[2]);
    return {
      id: id('PUR', i + 1), supplier: supplierFor[r[0]], invoiceNo: 'INV/' + digits(4), productId: p.id, qty: r[1], unitCost: p.purchasePrice + r[4],
      gstRate: p.gstRate, gstId: g, accountId: gstAcc(g), date: ago(r[3])
    };
  });

  /* ---------- Sales ---------- */
  const orderId = name => name === 'Flipkart' ? 'OD' + digits(15) : name === 'Amazon' ? `${digits(3)}-${digits(7)}-${digits(7)}` : digits(9) + '_1';
  const salePlan = [
    // daysAgo, product, qty, platform#, gst#, status
    [0, 3, 12, 3, 4, 'Pending'], [1, 1, 24, 1, 2, 'Shipped'], [2, 8, 60, 2, 4, 'Shipped'], [4, 4, 20, 3, 3, 'Delivered'],
    [6, 2, 40, 1, 1, 'Delivered'], [9, 7, 16, 3, 3, 'Delivered'], [12, 5, 12, 2, 2, 'Delivered'], [16, 10, 18, 3, 4, 'Delivered'],
    [21, 6, 8, 1, 1, 'Cancelled'], [27, 9, 10, 3, 3, 'Delivered'], [34, 1, 36, 2, 2, 'Delivered'], [42, 3, 20, 1, 1, 'Delivered'],
    [55, 8, 80, 1, 2, 'Delivered'], [68, 2, 50, 2, 4, 'Delivered'], [80, 4, 28, 3, 3, 'Delivered'], [96, 7, 20, 3, 3, 'Delivered'],
    [112, 3, 24, 3, 4, 'Delivered'], [130, 1, 40, 1, 1, 'Delivered'], [150, 6, 10, 2, 2, 'Delivered'], [170, 2, 44, 1, 1, 'Delivered']
  ];
  const sales = salePlan.map((r, i) => {
    const p = prod(r[1]); const pl = platforms[r[3] - 1];
    const price = p.sellingPrice; const gross = price * r[2];
    return {
      id: id('SAL', i + 1), orderId: orderId(pl.name), date: ago(r[0]), platformId: pl.id, gstId: id('GST', r[4]), productId: p.id,
      qty: r[2], price, gstRate: p.gstRate, commission: Math.round(gross * pl.commission / 100), shipping: r[2] * pl.shipPerUnit, status: r[5]
    };
  });

  /* ---------- Returns ---------- */
  const retPlan = [
    // sale#, qty, daysAgo, status, restock, reason
    [18, 3, 125, 'Refunded', 'Restocked', 'Size issue'],
    [14, 4, 62, 'Refunded', 'Restocked', 'Customer changed mind'],
    [12, 1, 38, 'Refunded', 'Damaged', 'Defective item'],
    [11, 2, 28, 'Approved', 'Pending', 'Colour mismatch'],
    [5, 2, 2, 'Requested', 'Pending', 'Wrong size delivered']
  ];
  const returns = retPlan.map((r, i) => {
    const s = sales[r[0] - 1];
    return { id: id('RET', i + 1), saleId: s.id, qty: r[1], refund: s.price * r[1], date: ago(r[2]), status: r[3], restock: r[4], reason: r[5] };
  });

  /* ---------- Expenses ---------- */
  const expPlan = [
    ['Rent', 'Warehouse rent – Ahmedabad godown', 1, 9000, 175],
    ['Salary', 'Packing staff salary – 2 helpers', 1, 12000, 150],
    ['Advertising', 'Flipkart Ads campaign – Kurti range', 2, 4500, 140],
    ['Packaging', 'Corrugated boxes & poly mailers', 2, 3200, 128],
    ['Shipping', 'Courier pickup charges (forward)', 3, 2100, 115],
    ['Electricity', 'Warehouse electricity bill', 1, 1850, 104],
    ['Marketing', 'Instagram creator shout-outs', 4, 5000, 92],
    ['Transportation', 'Tempo hire – supplier pickup, Surat', 2, 1400, 84],
    ['Bank Charges', 'NEFT/IMPS charges & SMS alerts', 3, 354, 70],
    ['Salary', 'Packing staff salary – 2 helpers', 1, 12000, 60],
    ['Advertising', 'Amazon Sponsored Products', 4, 6200, 48],
    ['Rent', 'Warehouse rent – Ahmedabad godown', 1, 9000, 35],
    ['Miscellaneous', 'Stationery, tape & label printer rolls', 3, 1150, 22],
    ['Packaging', 'Bubble wrap & fragile stickers', 4, 1800, 10],
    ['Shipping', 'Return pickup charges (RTO)', 2, 780, 3]
  ];
  const expenses = expPlan.map((r, i) => {
    const g = id('GST', r[2]);
    return { id: id('EXP', i + 1), category: r[0], desc: r[1], gstId: g, accountId: gstAcc(g), amount: r[3], date: ago(r[4]) };
  });

  const data = {
    version: 1,
    seededOn: iso(today),
    settings: { company: 'Demo Traders LLP', tagline: 'Multi-partner e-commerce business', showBanner: true },
    partners, investments, gsts, accounts, transfers, platforms, products, purchases, sales, returns, expenses, settlements: [],
    counters: {}
  };

  /* ---------- Settlements (computed from the sales above) ---------- */
  const stlPlan = [
    // platform#, gst#, fromAgo, toAgo, status, settledAgo
    [1, 1, 185, 120, 'Received', 113],
    [2, 2, 185, 140, 'Received', 134],
    [3, 3, 120, 70, 'Received', 63],
    [1, 2, 90, 40, 'Received', 33],
    [3, 4, 130, 10, 'Pending', null]
  ];
  data.settlements = stlPlan.map((r, i) => {
    const pl = id('PLT', r[0]), g = id('GST', r[1]);
    const from = ago(r[2]), to = ago(r[3]);
    const c = calcSettlement(pl, g, from, to, data);
    return {
      id: id('STL', i + 1), platformId: pl, gstId: g, from, to, gross: c.gross, returns: c.returns, commission: c.commission,
      shipping: c.shipping, other: c.other, net: c.net, accountId: gstAcc(g), status: r[4], settledOn: r[5] == null ? '' : ago(r[5]),
      utr: r[5] == null ? '' : 'SETLDEMO' + digits(8)
    };
  });

  data.counters = {
    P: partners.length, INV: investments.length, GST: gsts.length, ACC: accounts.length, TRF: transfers.length, PLT: platforms.length,
    PRD: products.length, PUR: purchases.length, SAL: sales.length, RET: returns.length, EXP: expenses.length, STL: data.settlements.length
  };
  return data;
}
