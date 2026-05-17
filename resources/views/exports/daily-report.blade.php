<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Laporan Harian</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica', sans-serif;
            font-size: 11px;
            color: #333;
            padding: 30px;
        }

        .header {
            text-align: center;
            margin-bottom: 24px;
            border-bottom: 2px solid #333;
            padding-bottom: 16px;
        }

        .header h1 {
            font-size: 20px;
            letter-spacing: 2px;
            margin-bottom: 4px;
        }

        .header p {
            font-size: 11px;
            color: #666;
        }

        .stats {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }

        .stat-box {
            display: table-cell;
            width: 25%;
            text-align: center;
            padding: 12px;
            border: 1px solid #ddd;
        }

        .stat-box .label {
            font-size: 9px;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-box .value {
            font-size: 18px;
            font-weight: bold;
            margin-top: 4px;
        }

        .stat-box .value.green {
            color: #16a34a;
        }

        .stat-box .value.blue {
            color: #2563eb;
        }

        .stat-box .value.red {
            color: #dc2626;
        }

        h2 {
            font-size: 13px;
            margin: 20px 0 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #555;
            border-bottom: 1px solid #eee;
            padding-bottom: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }

        th {
            background: #f5f5f5;
            text-align: left;
            padding: 6px 8px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #666;
            border-bottom: 2px solid #ddd;
        }

        td {
            padding: 5px 8px;
            border-bottom: 1px solid #eee;
            font-size: 11px;
        }

        tr:nth-child(even) {
            background: #fafafa;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .bold {
            font-weight: bold;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9px;
            color: #aaa;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }

        .payment-table td {
            padding: 4px 8px;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>LAWANG SEWU</h1>
        <p>Laporan Harian — {{ \Carbon\Carbon::parse($date)->format('d F Y') }}</p>
    </div>

    <div class="stats">
        <div class="stat-box">
            <div class="label">Pendapatan</div>
            <div class="value green">Rp {{ number_format($total_revenue, 0, ',', '.') }}</div>
        </div>
        <div class="stat-box">
            <div class="label">Total Pesanan</div>
            <div class="value blue">{{ $total_orders }}</div>
        </div>
        <div class="stat-box">
            <div class="label">Rata-rata/Order</div>
            <div class="value">Rp
                {{ $total_orders > 0 ? number_format($total_revenue / $total_orders, 0, ',', '.') : 0 }}</div>
        </div>
        <div class="stat-box">
            <div class="label">Void</div>
            <div class="value red">{{ $total_voided }}</div>
        </div>
    </div>

    <h2>Metode Pembayaran</h2>
    <table class="payment-table">
        <thead>
            <tr>
                <th>Metode</th>
                <th class="text-center">Jumlah</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payment_breakdown as $p)
                <tr>
                    <td>{{ strtoupper($p->payment_method) }}</td>
                    <td class="text-center">{{ $p->count }}</td>
                    <td class="text-right">Rp {{ number_format($p->total, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Menu Terjual — Prediksi Kebutuhan Besok</h2>
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Menu</th>
                <th>Kategori</th>
                <th class="text-center">Terjual</th>
                <th class="text-right">Pendapatan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($menu_sales as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="bold">{{ $item->menu->name }}</td>
                    <td>{{ $item->menu->category->name ?? '-' }}</td>
                    <td class="text-center bold">{{ $item->total_sold }}</td>
                    <td class="text-right">Rp {{ number_format($item->total_revenue, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Detail Pesanan</h2>
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Customer</th>
                <th>Waktu</th>
                <th>Bayar</th>
                <th class="text-right">Total</th>
                <th class="text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($orders as $index => $order)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $order->customer_name ?: '-' }}</td>
                    <td>{{ \Carbon\Carbon::parse($order->created_at)->format('H:i') }}</td>
                    <td>{{ strtoupper($order->payment_method) }}</td>
                    <td class="text-right">Rp {{ number_format($order->total_price, 0, ',', '.') }}</td>
                    <td class="text-center">
                        {{ $order->status === 'completed' ? 'OK' : ($order->status === 'voided' ? 'VOID' : 'PENDING') }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Dicetak pada {{ now()->format('d/m/Y H:i') }} — Lawang Sewu POS System
    </div>
</body>

</html>
